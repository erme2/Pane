<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OrganizationTenancyService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationTenancyTest extends TestCase
{
    private OrganizationTenancyService $tenancy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenancy = app(OrganizationTenancyService::class);
    }

    public function test_user_can_hold_independent_memberships_without_discovering_unjoined_organizations(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $paneAdministrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);

        $firstOrganization = $this->createOrganization('First Workspace');
        $secondOrganization = $this->createOrganization('Second Workspace');
        $unjoinedOrganization = $this->createOrganization('Unjoined Workspace');

        $firstMembership = $this->tenancy->addOrReactivateMembership(
            $firstOrganization,
            $user,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );
        $secondMembership = $this->tenancy->addOrReactivateMembership(
            $secondOrganization,
            $user,
            OrganizationMembership::ROLE_USER
        );

        $this->assertNotSame($firstMembership->membership_id, $secondMembership->membership_id);
        $this->assertTrue(Gate::forUser($user)->allows('access', $firstOrganization));
        $this->assertTrue(Gate::forUser($user)->allows('access', $secondOrganization));
        $this->assertTrue(Gate::forUser($user)->denies('access', $unjoinedOrganization));
        $this->assertTrue(Gate::forUser($user)->denies('viewAny', Organization::class));
        $this->assertTrue(Gate::forUser($paneAdministrator)->allows('viewAny', Organization::class));
    }

    public function test_suspension_preserves_membership_uuid_and_reactivation_reuses_it(): void
    {
        $organization = $this->createOrganization('Reactivation Workspace');
        $administrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $member = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);

        $this->tenancy->addOrReactivateMembership(
            $organization,
            $administrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );
        $membership = $this->tenancy->addOrReactivateMembership(
            $organization,
            $member,
            OrganizationMembership::ROLE_USER
        );

        $suspended = $this->tenancy->suspendMembership($membership);
        $reactivated = $this->tenancy->addOrReactivateMembership(
            $organization,
            $member,
            OrganizationMembership::ROLE_USER
        );

        $this->assertSame($membership->membership_id, $suspended->membership_id);
        $this->assertSame($membership->membership_id, $reactivated->membership_id);
        $this->assertSame(OrganizationMembership::STATUS_ACTIVE, $reactivated->status);
        $this->assertNull($reactivated->suspended_at);
    }

    public function test_policy_separates_pane_admin_from_organization_roles_and_denies_cross_organization(): void
    {
        $paneAdministrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $organizationAdministrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $organizationUser = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $externalAdministrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);

        $organization = $this->createOrganization('Policy Workspace');
        $otherOrganization = $this->createOrganization('Other Policy Workspace');

        $this->tenancy->addOrReactivateMembership(
            $organization,
            $organizationAdministrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );
        $this->tenancy->addOrReactivateMembership(
            $organization,
            $organizationUser,
            OrganizationMembership::ROLE_USER
        );
        $this->tenancy->addOrReactivateMembership(
            $otherOrganization,
            $externalAdministrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        $this->assertTrue(Gate::forUser($paneAdministrator)->allows('viewAny', Organization::class));
        $this->assertTrue(Gate::forUser($paneAdministrator)->denies('access', $organization));
        $this->assertTrue(Gate::forUser($paneAdministrator)->denies('administer', $organization));
        $this->assertTrue(Gate::forUser($organizationAdministrator)->allows('administer', $organization));
        $this->assertTrue(Gate::forUser($organizationUser)->allows('access', $organization));
        $this->assertTrue(Gate::forUser($organizationUser)->denies('administer', $organization));
        $this->assertTrue(Gate::forUser($externalAdministrator)->denies('access', $organization));
        $this->assertTrue(Gate::forUser($externalAdministrator)->denies('administer', $organization));
    }

    public function test_final_active_organization_administrator_safeguard_is_transactional(): void
    {
        $organization = $this->createOrganization('Admin Guard Workspace');
        $administrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $secondAdministrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);

        $membership = $this->tenancy->addOrReactivateMembership(
            $organization,
            $administrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        try {
            $this->tenancy->suspendMembership($membership);
            $this->fail('Expected final organization administrator suspension to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Cannot suspend the final active organization administrator.',
                $exception->getMessage()
            );
        } finally {
            $this->assertSame(
                OrganizationMembership::STATUS_ACTIVE,
                $membership->fresh()->status
            );
        }

        try {
            $this->tenancy->addOrReactivateMembership(
                $organization,
                $administrator,
                OrganizationMembership::ROLE_USER
            );
            $this->fail('Expected final organization administrator demotion to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Cannot demote the final active organization administrator.',
                $exception->getMessage()
            );
            $this->assertSame(
                OrganizationMembership::ROLE_ADMINISTRATOR,
                $membership->fresh()->role
            );
        }

        $this->tenancy->addOrReactivateMembership(
            $organization,
            $secondAdministrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );
        $suspended = $this->tenancy->suspendMembership($membership);

        $this->assertSame(OrganizationMembership::STATUS_SUSPENDED, $suspended->status);
    }

    public function test_final_active_pane_administrator_safeguard_is_transactional(): void
    {
        User::query()->update(['is_active' => false]);

        $administrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $secondAdministrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);

        $this->tenancy->deactivatePaneAdministrator($secondAdministrator);

        try {
            $this->tenancy->deactivatePaneAdministrator($administrator);
            $this->fail('Expected final active Pane administrator deactivation to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Cannot deactivate the final active Pane administrator.',
                $exception->getMessage()
            );
            $this->assertTrue((bool) $administrator->fresh()->is_active);
        }

        $reactivatedSecondAdministrator = $secondAdministrator->fresh();
        $reactivatedSecondAdministrator->forceFill(['is_active' => true])->save();

        $deactivated = $this->tenancy->deactivatePaneAdministrator($administrator);

        $this->assertFalse((bool) $deactivated->is_active);
        $this->assertTrue((bool) $reactivatedSecondAdministrator->fresh()->is_active);
    }

    public function test_organization_slugs_are_normalized_and_unique(): void
    {
        Organization::query()->where('slug', 'acme-inc')->delete();

        $organization = $this->tenancy->createOrganization('Acme Inc', 'Acme Inc!');

        $this->assertSame('acme-inc', $organization->slug);
        $this->assertSame(0, $organization->active_database_connections);
        $this->expectException(QueryException::class);

        $this->tenancy->createOrganization('Acme Duplicate', 'acme inc');
    }

    private function createOrganization(string $name): Organization
    {
        return $this->tenancy->createOrganization($name, $name.' '.Str::uuid());
    }
}
