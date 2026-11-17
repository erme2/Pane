<?php

namespace Tests\Feature\Organizations;

use App\Models\ApplicationRegistration;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\OrganizationInvitationService;
use App\Services\OrganizationTenancyService;
use App\Support\PaneTable;
use Illuminate\Http\Response;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class OrganizationInvitationApiTest extends TestCase
{
    private OrganizationTenancyService $tenancy;

    private ApplicationRegistryService $applications;

    protected function setUp(): void
    {
        parent::setUp();

        OrganizationInvitation::query()->delete();
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

    public function test_invitation_urls_use_bound_v1_application_for_configured_latte_apps(): void
    {
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://customer.example.test');
        config()->set('services.latte.redirect_uris', ['https://customer.example.test/app']);
        $application = $this->applications->configuredLatteApplication();
        $organization = $this->organizationFor($application);
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

    public function test_invitations_can_target_another_frontend_of_the_organization(): void
    {
        [$console, $organization] = $this->configuredApplicationAndOrganization();
        $app = $this->latteApplication('https://app.example.test');
        $this->withCsrfToken()->actingAs($this->organizationAdministrator($organization));

        $create = $this
            ->withV1ApplicationSession($console)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                'email' => 'guest@example.com',
                'role' => OrganizationMembership::ROLE_USER,
                'target_application_id' => $app->application_id,
            ])
            ->assertCreated()
            ->assertJsonPath('meta.invitation_url', fn (string $url): bool => str_starts_with($url, 'https://app.example.test/auth/login?'));

        $this->assertInvitationUrlTargetsApplication((string) $create->json('meta.invitation_url'), 'https://app.example.test/home');
        $this->assertSame($create->json('meta.invitation_url'), $this->sentInvitationUrl('guest@example.com'));
        $this->assertSame($app->application_id, $this->auditedTarget('organization.membership.invite', $create->json('data.id')));

        $resend = $this
            ->withV1ApplicationSession($console)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', (string) $create->headers->get('ETag'))
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations/{$create->json('data.id')}/resends", [
                'target_application_id' => $app->application_id,
            ])
            ->assertCreated();

        $this->assertInvitationUrlTargetsApplication((string) $resend->json('meta.invitation_url'), 'https://app.example.test/home');
        $this->assertStringStartsWith('https://app.example.test/auth/login?', (string) $resend->json('meta.invitation_url'));
        $this->assertSame($app->application_id, $this->auditedTarget('organization.membership.invitation.resend', $resend->json('data.id')));
    }

    public function test_invitations_without_a_target_use_the_requesting_frontend(): void
    {
        [$console, $organization] = $this->configuredApplicationAndOrganization();
        $this->latteApplication('https://app.example.test');
        $this->withCsrfToken()->actingAs($this->organizationAdministrator($organization));

        $create = $this
            ->withV1ApplicationSession($console)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                'email' => 'guest@example.com',
                'role' => OrganizationMembership::ROLE_USER,
            ])
            ->assertCreated();

        $this->assertStringStartsWith('https://latte.test/auth/login?', (string) $create->json('meta.invitation_url'));
        $this->assertInvitationUrlTargetsApplication((string) $create->json('meta.invitation_url'), 'https://latte.test/dashboard');
        $this->assertSame($console->application_id, $this->auditedTarget('organization.membership.invite', $create->json('data.id')));
    }

    public function test_invitation_targets_must_be_configured_frontends(): void
    {
        [$console, $organization] = $this->configuredApplicationAndOrganization();
        $this->withCsrfToken()->actingAs($this->organizationAdministrator($organization));

        foreach ([(string) Str::uuid(), 'not-a-uuid', null] as $target) {
            $this
                ->withV1ApplicationSession($console)
                ->withHeader('Origin', 'https://latte.test')
                ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                    'email' => 'guest@example.com',
                    'role' => OrganizationMembership::ROLE_USER,
                    'target_application_id' => $target,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed');
        }

        $this->assertSame(0, OrganizationInvitation::query()->count());

        $create = $this
            ->withV1ApplicationSession($console)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                'email' => 'guest@example.com',
                'role' => OrganizationMembership::ROLE_USER,
            ])
            ->assertCreated();

        foreach ([['target_application_id' => (string) Str::uuid()], ['role' => 'organization_administrator']] as $body) {
            $this
                ->withV1ApplicationSession($console)
                ->withHeader('Origin', 'https://latte.test')
                ->withHeader('If-Match', (string) $create->headers->get('ETag'))
                ->postJson("/api/v1/organizations/$organization->organization_id/invitations/{$create->json('data.id')}/resends", $body)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed');
        }

        $this->assertSame(OrganizationInvitation::STATUS_PENDING, OrganizationInvitation::query()->findOrFail($create->json('data.id'))->status);
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

    public function test_resend_rejects_an_accepted_invitation_with_documented_conflict(): void
    {
        [$application, $organization] = $this->configuredApplicationAndOrganization();
        $administrator = $this->organizationAdministrator($organization);
        $result = app(OrganizationInvitationService::class)->inviteOrganizationMember(
            $administrator,
            $organization,
            'accepted@example.com',
            OrganizationMembership::ROLE_USER,
        );
        app(OrganizationInvitationService::class)->acceptOrganizationInvitation(
            $organization,
            $result['token'],
            [
                'id' => 'user_accepted',
                'email' => 'accepted@example.com',
                'email_verified' => true,
            ],
        );
        /** @var OrganizationInvitation $invitation */
        $invitation = $result['invitation']->fresh();

        $this
            ->withCsrfToken()
            ->actingAs($administrator)
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', '"'.$invitation->versionTag().'"')
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations/{$invitation->getKey()}/resends")
            ->assertConflict()
            ->assertJsonPath('error.code', 'operation_conflict')
            ->assertJsonPath('error.message', 'Accepted organization invitations cannot be resent.');

        $this->assertSame(OrganizationInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertSame(1, OrganizationInvitation::query()->count());
    }

    public function test_create_and_resend_each_send_one_invitation_email_and_revoke_sends_none(): void
    {
        [$application, $organization] = $this->configuredApplicationAndOrganization();
        $this->withCsrfToken()->actingAs($this->organizationAdministrator($organization));

        $create = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                'email' => 'Sent@Example.com',
                'role' => OrganizationMembership::ROLE_USER,
            ])
            ->assertCreated();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertSame('sent@example.com', $messages[0]->getTo()[0]->getAddress());
        $this->assertSame("You're invited to {$organization->name}", $messages[0]->getSubject());
        $this->assertStringContainsString((string) $create->json('meta.invitation_url'), (string) $messages[0]->getTextBody());

        $resend = $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', (string) $create->headers->get('ETag'))
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations/{$create->json('data.id')}/resends")
            ->assertCreated();

        $this->assertCount(2, $this->sentMessages());
        $this->assertSame($resend->json('meta.invitation_url'), $this->sentInvitationUrl('sent@example.com'));

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', (string) $resend->headers->get('ETag'))
            ->deleteJson("/api/v1/organizations/$organization->organization_id/invitations/{$resend->json('data.id')}")
            ->assertNoContent();

        $this->assertCount(2, $this->sentMessages());
    }

    public function test_a_failed_invitation_sends_no_email(): void
    {
        [$application, $organization] = $this->configuredApplicationAndOrganization();
        $administrator = $this->organizationAdministrator($organization);
        $member = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $this->tenancy->addOrReactivateMembership($organization, $member, OrganizationMembership::ROLE_USER);

        $this
            ->withCsrfToken()
            ->actingAs($administrator)
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                'email' => $member->email,
                'role' => OrganizationMembership::ROLE_USER,
            ])
            ->assertConflict();

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_a_failed_email_send_keeps_the_invitation(): void
    {
        [$application, $organization] = $this->configuredApplicationAndOrganization();
        $this->withCsrfToken()->actingAs($this->organizationAdministrator($organization));
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('smtp down'));

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson("/api/v1/organizations/$organization->organization_id/invitations", [
                'email' => 'down@example.com',
                'role' => OrganizationMembership::ROLE_USER,
            ])
            ->assertCreated()
            ->assertJsonPath('meta.invitation_url', fn (string $url): bool => str_starts_with($url, 'https://latte.test/auth/login?'));

        $this->assertSame(1, OrganizationInvitation::query()->where('email', 'down@example.com')->count());
    }

    /**
     * @return array<int, Email>
     */
    private function sentMessages(): array
    {
        $transport = Mail::mailer()->getSymfonyTransport();

        if (! $transport instanceof ArrayTransport) {
            $this->fail('The testing mailer must use the array transport.');
        }

        return $transport->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->values()
            ->all();
    }

    private function sentInvitationUrl(string $recipient): string
    {
        foreach (array_reverse($this->sentMessages()) as $message) {
            if ($message->getTo()[0]->getAddress() === $recipient) {
                preg_match('~https?://\S+~', (string) $message->getTextBody(), $matches);

                return $matches[0];
            }
        }

        $this->fail("No email was sent to $recipient.");
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
        $organization = $this->organizationFor($application);

        return [$application, $organization];
    }

    private function auditedTarget(string $action, string $invitationId): mixed
    {
        $event = AuditEvent::query()
            ->where('action', $action)
            ->get()
            ->sole(fn (AuditEvent $event): bool => ($event->resource_ids['organization_invitation_id'] ?? null) === $invitationId);

        return $event->metadata['target_application_id'] ?? null;
    }

    private function latteApplication(string $origin): ApplicationRegistration
    {
        $configured = (string) config('services.latte.extra_frontend_origins');
        config()->set('services.latte.extra_frontend_origins', trim($configured.';'.$origin.'|'.$origin.'/home', ';'));

        return $this->applications->activeApplicationForOrigin($origin) ?? $this->fail('Frontend not configured.');
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
