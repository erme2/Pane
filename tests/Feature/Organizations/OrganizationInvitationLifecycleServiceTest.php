<?php

namespace Tests\Feature\Organizations;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OrganizationInvitationService;
use App\Services\OrganizationTenancyService;
use App\Services\SettingsService;
use App\Support\PaneTable;
use App\Support\SettingsRegistry;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class OrganizationInvitationLifecycleServiceTest extends TestCase
{
    private OrganizationInvitationService $invitations;

    private OrganizationTenancyService $tenancy;

    protected function setUp(): void
    {
        parent::setUp();

        OrganizationInvitation::query()->delete();
        OrganizationMembership::query()->delete();
        Organization::query()->delete();
        User::query()->delete();

        $this->invitations = app(OrganizationInvitationService::class);
        $this->tenancy = app(OrganizationTenancyService::class);
    }

    public function test_organization_invitation_acceptance_is_email_bound_single_use_and_scoped_to_organization(): void
    {
        $firstOrganization = $this->createOrganization('Acme Workspace');
        $secondOrganization = $this->createOrganization('Beta Workspace');
        $firstAdministrator = $this->organizationAdministrator($firstOrganization);
        $secondAdministrator = $this->organizationAdministrator($secondOrganization);

        $first = $this->invitations->inviteOrganizationMember(
            $firstAdministrator,
            $firstOrganization,
            'Invited.Member@Example.COM',
            OrganizationMembership::ROLE_USER
        );
        $second = $this->invitations->inviteOrganizationMember(
            $secondAdministrator,
            $secondOrganization,
            'invited.member@example.com',
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        /** @var OrganizationInvitation $firstInvitation */
        $firstInvitation = $first['invitation'];

        $this->assertSame('invited.member@example.com', $firstInvitation->email);
        $this->assertSame($firstOrganization->organization_id, $firstInvitation->organization_id);
        $this->assertSame(64, strlen($firstInvitation->token_hash));
        $this->assertNotSame($first['token'], $firstInvitation->token_hash);
        $this->assertFalse(
            DB::table(PaneTable::name(PaneTable::ORGANIZATION_INVITATIONS))
                ->where('token_hash', $first['token'])
                ->exists()
        );

        try {
            $this->invitations->acceptOrganizationInvitation($firstOrganization, $first['token'], [
                'id' => 'user_wrong',
                'email' => 'wrong@example.com',
                'email_verified' => true,
            ]);
            $this->fail('Expected email-mismatched invitation acceptance to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Organization invitation email does not match the WorkOS identity.',
                $exception->getMessage()
            );
        }

        $accepted = $this->invitations->acceptOrganizationInvitation(
            $firstOrganization,
            $first['token'],
            [
                'id' => 'user_invited',
                'email' => 'invited.member@example.com',
                'email_verified' => true,
                'first_name' => 'Invited',
                'last_name' => 'Member',
            ],
            [
                'organization_id' => 'org_123',
                'authentication_method' => 'sso',
            ]
        );

        $this->assertSame(User::STANDARD_USER_TYPE_ID, $accepted->user_type_id);
        $this->assertSame('Invited Member', $accepted->name);
        $this->assertSame('user_invited', $accepted->workos_id);
        $this->assertTrue((bool) $accepted->is_active);
        $this->assertSame(OrganizationInvitation::STATUS_ACCEPTED, $firstInvitation->fresh()->status);

        $firstMembership = $firstOrganization->activeMembershipFor($accepted);
        $this->assertInstanceOf(OrganizationMembership::class, $firstMembership);
        $this->assertSame(OrganizationMembership::ROLE_USER, $firstMembership->role);

        $sameUser = $this->invitations->acceptOrganizationInvitation($secondOrganization, $second['token'], [
            'id' => 'user_invited',
            'email' => 'invited.member@example.com',
            'email_verified' => true,
        ]);

        $this->assertSame($accepted->getKey(), $sameUser->getKey());
        $secondMembership = $secondOrganization->activeMembershipFor($sameUser);
        $this->assertInstanceOf(OrganizationMembership::class, $secondMembership);
        $this->assertSame(OrganizationMembership::ROLE_ADMINISTRATOR, $secondMembership->role);

        try {
            $this->invitations->acceptOrganizationInvitation($firstOrganization, $first['token'], [
                'id' => 'user_invited',
                'email' => 'invited.member@example.com',
                'email_verified' => true,
            ]);
            $this->fail('Expected single-use invitation acceptance to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Organization invitation was already accepted.', $exception->getMessage());
        }

        $this->assertStringNotContainsString($first['token'], AuditEvent::query()->get()->toJson());
        $this->assertStringNotContainsString($second['token'], AuditEvent::query()->get()->toJson());
    }

    public function test_resend_revocation_expiry_and_organization_expiry_override_invalidate_old_tokens(): void
    {
        $organization = $this->createOrganization('Expiry Workspace');
        $administrator = $this->organizationAdministrator($organization);
        $settings = app(SettingsService::class);

        Carbon::setTestNow(now()->startOfSecond());
        $settings->setOrganizationOverride(
            $administrator,
            $organization,
            SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
            3600
        );

        $first = $this->invitations->inviteOrganizationMember(
            $administrator,
            $organization,
            'second@example.com',
            OrganizationMembership::ROLE_USER
        );

        $this->assertSame(
            now()->addSeconds(3600)->toJSON(),
            $first['invitation']->expires_at->toJSON()
        );

        $second = $this->invitations->resendOrganizationInvitation(
            $administrator,
            $organization,
            $first['invitation']
        );

        $this->assertSame(OrganizationInvitation::STATUS_REVOKED, $first['invitation']->fresh()->status);

        try {
            $this->invitations->acceptOrganizationInvitation($organization, $first['token'], [
                'id' => 'user_second',
                'email' => 'second@example.com',
                'email_verified' => true,
            ]);
            $this->fail('Expected replaced invitation token to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Organization invitation was revoked.', $exception->getMessage());
        }

        $accepted = $this->invitations->acceptOrganizationInvitation($organization, $second['token'], [
            'id' => 'user_second',
            'email' => 'second@example.com',
            'email_verified' => true,
        ]);

        $this->assertInstanceOf(OrganizationMembership::class, $organization->activeMembershipFor($accepted));

        $expired = $this->invitations->inviteOrganizationMember(
            $administrator,
            $organization,
            'expired@example.com',
            OrganizationMembership::ROLE_USER
        );
        $expired['invitation']->forceFill(['expires_at' => now()->subSecond()])->save();

        try {
            $this->invitations->acceptOrganizationInvitation($organization, $expired['token'], [
                'id' => 'user_expired',
                'email' => 'expired@example.com',
                'email_verified' => true,
            ]);
            $this->fail('Expected expired invitation token to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Organization invitation has expired.', $exception->getMessage());
        }

        $this->assertSame(OrganizationInvitation::STATUS_EXPIRED, $expired['invitation']->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_acceptance_reactivates_matching_suspended_membership_but_rejects_active_duplicates(): void
    {
        $organization = $this->createOrganization('Reactivation Workspace');
        $administrator = $this->organizationAdministrator($organization);
        $activeMember = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $suspendedMember = User::query()->create([
            'user_type_id' => User::STANDARD_USER_TYPE_ID,
            'name' => 'Suspended Member',
            'email' => 'suspended@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->tenancy->addOrReactivateMembership(
            $organization,
            $activeMember,
            OrganizationMembership::ROLE_USER
        );

        try {
            $this->invitations->inviteOrganizationMember(
                $administrator,
                $organization,
                $activeMember->email,
                OrganizationMembership::ROLE_USER
            );
            $this->fail('Expected duplicate active membership invitation to fail.');
        } catch (DomainException $exception) {
            $this->assertSame('Email already belongs to an active organization membership.', $exception->getMessage());
        }

        $membership = $this->tenancy->addOrReactivateMembership(
            $organization,
            $suspendedMember,
            OrganizationMembership::ROLE_USER
        );
        $this->tenancy->suspendMembership($membership);

        $invitation = $this->invitations->inviteOrganizationMember(
            $administrator,
            $organization,
            'suspended@example.com',
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        $accepted = $this->invitations->acceptOrganizationInvitation($organization, $invitation['token'], [
            'id' => 'user_suspended',
            'email' => 'suspended@example.com',
            'email_verified' => true,
        ]);
        $reactivated = $organization->activeMembershipFor($accepted);

        $this->assertInstanceOf(OrganizationMembership::class, $reactivated);
        $this->assertSame($membership->membership_id, $reactivated->membership_id);
        $this->assertSame(OrganizationMembership::ROLE_ADMINISTRATOR, $reactivated->role);
    }

    private function createOrganization(string $name): Organization
    {
        return $this->tenancy->createOrganization($name, $name.' '.Str::uuid());
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
