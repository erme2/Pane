<?php

namespace Tests\Feature\Organizations;

use App\Models\ApplicationRegistration;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\OrganizationTenancyService;
use App\Support\PaneTable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationInvitationApiTest extends TestCase
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

    public function test_organization_admin_can_create_list_resend_and_revoke_invitations_through_v1_api(): void
    {
        [$application, $organization] = $this->configuredApplicationAndOrganization();
        $actor = $this->organizationAdministrator($organization);
        $this->withCsrfToken()->actingAs($actor);

        $create = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                'email' => 'Invited.Member@Example.COM',
                'role' => OrganizationMembership::ROLE_USER,
            ]);

        $create
            ->assertCreated()
            ->assertHeader('X-Request-Id')
            ->assertHeader('ETag')
            ->assertJsonPath('meta.invitation_url', fn (string $url): bool => str_starts_with($url, 'https://latte.test/auth/login?'))
            ->assertJsonPath('data.type', 'invitation')
            ->assertJsonPath('data.attributes.scope', 'organization')
            ->assertJsonPath('data.attributes.organization_id', $organization->organization_id)
            ->assertJsonPath('data.attributes.email', 'invited.member@example.com')
            ->assertJsonPath('data.attributes.role', OrganizationMembership::ROLE_USER)
            ->assertJsonPath('data.attributes.status', OrganizationInvitation::STATUS_PENDING);

        $invitationId = $create->json('data.id');
        $invitationUrl = (string) $create->json('meta.invitation_url');
        $query = [];
        parse_str((string) parse_url($invitationUrl, PHP_URL_QUERY), $query);

        $this->assertIsString($query['invitation_token'] ?? null);
        $this->assertSame(
            hash('sha256', $query['invitation_token']),
            OrganizationInvitation::query()->findOrFail($invitationId)->token_hash
        );
        $this->assertFalse(
            DB::table(PaneTable::name(PaneTable::ORGANIZATION_INVITATIONS))
                ->where('token_hash', $query['invitation_token'])
                ->exists()
        );

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->getJson("/api/v1/organizations/$organization->organization_id/invitations/$invitationId")
            ->assertOk()
            ->assertHeader('ETag', (string) $create->headers->get('ETag'))
            ->assertJsonPath('data.id', $invitationId)
            ->assertJsonPath('meta.request_id', fn (string $requestId): bool => Str::isUuid($requestId));

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->getJson("/api/v1/organizations/$organization->organization_id/invitations")
            ->assertOk()
            ->assertJsonPath('data.0.id', $invitationId)
            ->assertJsonPath('meta.page.has_more', false);

        $resend = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', (string) $create->headers->get('ETag'))
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations/$invitationId/resends");

        $resend
            ->assertCreated()
            ->assertHeader('ETag')
            ->assertJsonPath('data.attributes.status', OrganizationInvitation::STATUS_PENDING);

        $this->assertSame(
            OrganizationInvitation::STATUS_REVOKED,
            OrganizationInvitation::query()->findOrFail($invitationId)->status
        );

        $replacementId = $resend->json('data.id');

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', (string) $resend->headers->get('ETag'))
            ->deleteJson("/api/v1/organizations/$organization->organization_id/invitations/$replacementId")
            ->assertNoContent();

        $this->assertSame(
            OrganizationInvitation::STATUS_REVOKED,
            OrganizationInvitation::query()->findOrFail($replacementId)->status
        );
    }

    public function test_invitation_urls_use_bound_v1_application_for_registered_latte_apps(): void
    {
        $organization = $this->tenancy->createOrganization('Customer Workspace', 'customer-workspace-'.Str::uuid());
        $application = ApplicationRegistration::query()->create([
            'name' => 'Customer Latte',
            'kind' => ApplicationRegistration::KIND_LATTE,
            'organization_id' => $organization->organization_id,
            'trusted_origin' => 'https://customer.example.test',
            'redirect_uris' => ['https://customer.example.test/app'],
            'status' => ApplicationRegistration::STATUS_ACTIVE,
        ]);
        $actor = $this->organizationAdministrator($organization);
        $this->withCsrfToken()->actingAs($actor);

        $create = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://customer.example.test')
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                'email' => 'new.member@example.com',
                'role' => OrganizationMembership::ROLE_USER,
            ]);

        $create
            ->assertCreated()
            ->assertJsonPath('meta.invitation_url', fn (string $url): bool => str_starts_with($url, 'https://customer.example.test/auth/login?'));

        $this->assertInvitationUrlTargetsApplication(
            (string) $create->json('meta.invitation_url'),
            'https://customer.example.test/app'
        );

        $resend = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://customer.example.test')
            ->withHeader('If-Match', (string) $create->headers->get('ETag'))
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations/{$create->json('data.id')}/resends");

        $resend
            ->assertCreated()
            ->assertJsonPath('meta.invitation_url', fn (string $url): bool => str_starts_with($url, 'https://customer.example.test/auth/login?'));

        $this->assertInvitationUrlTargetsApplication(
            (string) $resend->json('meta.invitation_url'),
            'https://customer.example.test/app'
        );
    }

    public function test_organization_invitation_api_does_not_leak_other_organizations_or_allow_non_admins(): void
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
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                'email' => 'new@example.com',
                'role' => OrganizationMembership::ROLE_USER,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'permission_denied');

        $this
            ->withCsrfToken()
            ->actingAs($administrator)
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson("/api/v1/organizations/$otherOrganization->organization_id/invitations", [
                'email' => 'new@example.com',
                'role' => OrganizationMembership::ROLE_USER,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'organization_context_mismatch');
    }

    public function test_create_rejects_duplicate_active_memberships_and_unsupported_fields(): void
    {
        [$application, $organization] = $this->configuredApplicationAndOrganization();
        $administrator = $this->organizationAdministrator($organization);
        $member = User::query()->create([
            'user_type_id' => User::STANDARD_USER_TYPE_ID,
            'name' => 'Existing Member',
            'email' => 'member@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->tenancy->addOrReactivateMembership($organization, $member, OrganizationMembership::ROLE_USER);

        $this
            ->withCsrfToken()
            ->actingAs($administrator)
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                'email' => 'member@example.com',
                'role' => OrganizationMembership::ROLE_USER,
            ])
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('error.code', 'operation_conflict');

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                'email' => 'new@example.com',
                'role' => OrganizationMembership::ROLE_USER,
                'organization_id' => $organization->organization_id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
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

    private function assertInvitationUrlTargetsApplication(string $url, string $redirectUri): void
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertIsString($query['invitation_token'] ?? null);
        $this->assertSame($redirectUri, $query['redirect_to'] ?? null);
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
