<?php

namespace Tests\Feature\Organizations;

use App\Models\ApplicationRegistration;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\OrganizationTenancyService;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationMembershipApiTest extends TestCase
{
    private OrganizationTenancyService $tenancy;

    private ApplicationRegistryService $applications;

    protected function setUp(): void
    {
        parent::setUp();

        OrganizationInvitation::query()->delete();
        ApplicationRegistration::query()->delete();
        OrganizationMembership::query()->delete();
        Organization::query()->delete();
        User::query()->delete();

        $this->tenancy = app(OrganizationTenancyService::class);
        $this->applications = app(ApplicationRegistryService::class);
    }

    public function test_organization_admin_can_list_get_and_update_memberships_through_v1_api(): void
    {
        [$application, $organization] = $this->configuredApplicationAndOrganization();
        $administrator = $this->organizationAdministrator($organization);
        $member = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $membership = $this->tenancy->addOrReactivateMembership(
            $organization,
            $member,
            OrganizationMembership::ROLE_USER
        );

        $this->withCsrfToken()->actingAs($administrator);

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->getJson("/api/v1/organizations/$organization->organization_id/memberships")
            ->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('data.0.type', 'membership')
            ->assertJsonPath('meta.page.has_more', false);

        $show = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->getJson("/api/v1/organizations/$organization->organization_id/memberships/$membership->membership_id");

        $show
            ->assertOk()
            ->assertHeader('ETag')
            ->assertJsonPath('data.id', $membership->membership_id)
            ->assertJsonPath('data.attributes.user_email', $member->email)
            ->assertJsonPath('data.attributes.user_name', $member->name)
            ->assertJsonPath('data.attributes.role', OrganizationMembership::ROLE_USER)
            ->assertJsonPath('data.attributes.status', OrganizationMembership::STATUS_ACTIVE);

        $promote = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', (string) $show->headers->get('ETag'))
            ->patchJson("/api/v1/organizations/$organization->organization_id/memberships/$membership->membership_id", [
                'role' => OrganizationMembership::ROLE_ADMINISTRATOR,
            ]);

        $promote
            ->assertOk()
            ->assertHeader('ETag')
            ->assertJsonPath('data.attributes.role', OrganizationMembership::ROLE_ADMINISTRATOR)
            ->assertJsonPath('data.attributes.status', OrganizationMembership::STATUS_ACTIVE);

        $suspend = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', (string) $promote->headers->get('ETag'))
            ->patchJson("/api/v1/organizations/$organization->organization_id/memberships/$membership->membership_id", [
                'status' => OrganizationMembership::STATUS_SUSPENDED,
            ]);

        $suspend
            ->assertOk()
            ->assertJsonPath('data.attributes.role', OrganizationMembership::ROLE_ADMINISTRATOR)
            ->assertJsonPath('data.attributes.status', OrganizationMembership::STATUS_SUSPENDED);

        $reactivate = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', (string) $suspend->headers->get('ETag'))
            ->patchJson("/api/v1/organizations/$organization->organization_id/memberships/$membership->membership_id", [
                'status' => OrganizationMembership::STATUS_ACTIVE,
            ]);

        $reactivate
            ->assertOk()
            ->assertJsonPath('data.attributes.role', OrganizationMembership::ROLE_ADMINISTRATOR)
            ->assertJsonPath('data.attributes.status', OrganizationMembership::STATUS_ACTIVE);
    }

    public function test_membership_api_denies_non_admins_and_cross_organization_access(): void
    {
        [$application, $organization] = $this->configuredApplicationAndOrganization();
        $otherOrganization = $this->tenancy->createOrganization('Other Workspace', 'other-workspace-'.Str::uuid());
        $administrator = $this->organizationAdministrator($organization);
        $member = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $this->tenancy->addOrReactivateMembership($organization, $member, OrganizationMembership::ROLE_USER);

        $this
            ->withCsrfToken()
            ->actingAs($member)
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->getJson("/api/v1/organizations/$organization->organization_id/memberships")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'permission_denied');

        $this
            ->withCsrfToken()
            ->actingAs($administrator)
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->getJson("/api/v1/organizations/$otherOrganization->organization_id/memberships")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'organization_context_mismatch');
    }

    public function test_membership_update_requires_strong_current_if_match(): void
    {
        [$application, $organization] = $this->configuredApplicationAndOrganization();
        $administrator = $this->organizationAdministrator($organization);
        $member = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $membership = $this->tenancy->addOrReactivateMembership($organization, $member, OrganizationMembership::ROLE_USER);

        $this->withCsrfToken()->actingAs($administrator);

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->patchJson("/api/v1/organizations/$organization->organization_id/memberships/$membership->membership_id", [
                'status' => OrganizationMembership::STATUS_SUSPENDED,
            ])
            ->assertStatus(Response::HTTP_PRECONDITION_REQUIRED)
            ->assertJsonPath('error.code', 'precondition_required');

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', 'W/"revision_42"')
            ->patchJson("/api/v1/organizations/$organization->organization_id/memberships/$membership->membership_id", [
                'status' => OrganizationMembership::STATUS_SUSPENDED,
            ])
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'invalid_request');

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', '"revision_42"')
            ->patchJson("/api/v1/organizations/$organization->organization_id/memberships/$membership->membership_id", [
                'status' => OrganizationMembership::STATUS_SUSPENDED,
            ])
            ->assertStatus(Response::HTTP_PRECONDITION_FAILED)
            ->assertJsonPath('error.code', 'version_conflict');
    }

    public function test_membership_role_update_preserves_invitation_history(): void
    {
        [$application, $organization] = $this->configuredApplicationAndOrganization();
        $administrator = $this->organizationAdministrator($organization);
        $member = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $inviter = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $acceptedAt = now()->subDays(3)->startOfSecond();
        $membership = $this->tenancy->addOrReactivateMembership(
            $organization,
            $member,
            OrganizationMembership::ROLE_USER,
            $inviter,
            $acceptedAt
        );

        $this->withCsrfToken()->actingAs($administrator);

        $show = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->getJson("/api/v1/organizations/$organization->organization_id/memberships/$membership->membership_id");

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', (string) $show->headers->get('ETag'))
            ->patchJson("/api/v1/organizations/$organization->organization_id/memberships/$membership->membership_id", [
                'role' => OrganizationMembership::ROLE_ADMINISTRATOR,
            ])
            ->assertOk()
            ->assertJsonPath('data.attributes.role', OrganizationMembership::ROLE_ADMINISTRATOR);

        $membership->refresh();

        $this->assertSame($inviter->getKey(), $membership->invited_by_user_id);
        $this->assertTrue($membership->accepted_at->equalTo($acceptedAt));
    }

    public function test_membership_update_enforces_final_active_administrator_safeguards(): void
    {
        [$application, $organization] = $this->configuredApplicationAndOrganization();
        $administrator = $this->organizationAdministrator($organization);
        $membership = $organization->activeMembershipFor($administrator);
        $this->assertInstanceOf(OrganizationMembership::class, $membership);

        $this->withCsrfToken()->actingAs($administrator);

        $show = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->getJson("/api/v1/organizations/$organization->organization_id/memberships/$membership->membership_id");

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', (string) $show->headers->get('ETag'))
            ->patchJson("/api/v1/organizations/$organization->organization_id/memberships/$membership->membership_id", [
                'status' => OrganizationMembership::STATUS_SUSPENDED,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'operation_conflict')
            ->assertJsonPath('error.message', 'Cannot suspend the final active organization administrator.');
    }

    /**
     * @return array{ApplicationRegistration, Organization}
     */
    private function configuredApplicationAndOrganization(): array
    {
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $application = $this->applications->configuredLatteApplication();
        $organization = $application->organization()->firstOrFail();

        return [$application, $organization];
    }

    private function organizationAdministrator(Organization $organization): User
    {
        $administrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);

        $this->tenancy->addOrReactivateMembership(
            $organization,
            $administrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        return $administrator;
    }
}
