<?php

namespace Tests\Feature;

use App\Models\ApplicationRegistration;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\PaneAdminInvitation;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\OrganizationInvitationService;
use App\Services\OrganizationTenancyService;
use App\Support\LatteApplicationConfig;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkOsAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        OrganizationInvitation::query()->delete();
        PaneAdminInvitation::query()->delete();
        ApplicationRegistration::query()->delete();
        OrganizationMembership::query()->delete();
        Organization::query()->delete();
        User::query()->delete();
    }

    public function test_login_redirects_to_workos(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test');
        config()->set('services.workos.return_to', 'https://pane.test');
        config()->set('services.workos.provider', 'authkit');

        $response = $this->get('/auth/login?redirect_to=https://pane.test/dashboard');

        $response->assertRedirectContains('https://api.workos.com/user_management/authorize');
        $response->assertSessionHas('workos_state');
        $response->assertSessionHas('workos_intended_url', 'https://pane.test/dashboard');
    }

    public function test_login_url_returns_workos_authorization_url(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test');
        config()->set('services.workos.return_to', 'https://latte.test');
        config()->set('services.workos.provider', 'authkit');

        $response = $this->getJson('/auth/login-url?redirect_to=https://latte.test/dashboard');

        $response
            ->assertOk()
            ->assertJsonStructure(['authorization_url', 'state'])
            ->assertSessionHas('workos_state')
            ->assertSessionHas('workos_intended_url', 'https://latte.test/dashboard');

        $this->assertStringStartsWith(
            'https://api.workos.com/user_management/authorize?',
            $response->json('authorization_url')
        );
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Flatte.test', $response->json('authorization_url'));
    }

    public function test_login_url_falls_back_when_redirect_to_is_external(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test');
        config()->set('services.workos.return_to', 'https://latte.test');
        config()->set('services.workos.provider', 'authkit');

        $response = $this->getJson('/auth/login-url?redirect_to=https://evil.test/dashboard');

        $response
            ->assertOk()
            ->assertSessionHas('workos_intended_url', 'https://latte.test');
    }

    public function test_login_url_accepts_relative_redirect_to(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test');
        config()->set('services.workos.return_to', 'https://latte.test');
        config()->set('services.workos.provider', 'authkit');

        $response = $this->getJson('/auth/login-url?redirect_to=/dashboard');

        $response
            ->assertOk()
            ->assertSessionHas('workos_intended_url', '/dashboard');
    }

    public function test_v1_csrf_cookie_bootstrap_accepts_post_without_existing_token(): void
    {
        $requestId = (string) Str::uuid();

        $response = $this
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/csrf-cookie');

        $response
            ->assertNoContent()
            ->assertHeader('X-Request-Id', $requestId);
    }

    public function test_v1_routes_are_covered_by_cors_preflight(): void
    {
        config()->set('cors.allowed_origins', ['https://latte.localhost']);

        $response = $this->withHeaders([
            'Origin' => 'https://latte.localhost',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type, X-XSRF-TOKEN, X-Request-Id',
        ])->options('/api/v1/auth/login-intents');

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://latte.localhost')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $this->assertStringContainsString(
            'X-Request-Id',
            $response->headers->get('Access-Control-Allow-Headers') ?? ''
        );
    }

    public function test_v1_cors_response_exposes_request_id_header(): void
    {
        $requestId = (string) Str::uuid();

        config()->set('cors.allowed_origins', ['https://latte.localhost']);

        $response = $this
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('X-Request-Id', $requestId)
            ->getJson('/api/v1/session');

        $response
            ->assertUnauthorized()
            ->assertHeader('X-Request-Id', $requestId)
            ->assertHeader('Access-Control-Allow-Origin', 'https://latte.localhost');

        $this->assertStringContainsString(
            'X-Request-Id',
            $response->headers->get('Access-Control-Expose-Headers') ?? ''
        );
    }

    public function test_v1_cors_uses_normalized_latte_frontend_origin(): void
    {
        config()->set('services.latte.frontend_url', 'https://LATTE.test:443/app');
        config()->set('cors.allowed_origins', [LatteApplicationConfig::trustedOrigin()]);

        $response = $this
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->options('/api/v1/auth/login-intents');

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://latte.test');
    }

    public function test_v1_login_intent_returns_versioned_payload(): void
    {
        $requestId = (string) Str::uuid();

        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test/auth/callback');
        config()->set('services.workos.return_to', 'https://latte.test');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $response = $this
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/login-intents', [
                'redirect_to' => 'https://latte.test/dashboard',
            ]);

        $response
            ->assertOk()
            ->assertHeader('X-Request-Id', $requestId)
            ->assertJsonPath('meta.request_id', $requestId)
            ->assertJsonStructure([
                'data' => ['authorization_url', 'state'],
                'meta' => ['request_id'],
            ])
            ->assertSessionHas('workos_state')
            ->assertSessionHas('workos_intended_url', 'https://latte.test/dashboard')
            ->assertSessionHas('pane_v1_application_id', config('services.latte.application_id'))
            ->assertSessionHas('pane_v1_application_session_version')
            ->assertSessionMissing('pane_v1_application');

        $this->assertStringStartsWith(
            'https://api.workos.com/user_management/authorize?',
            $response->json('data.authorization_url')
        );
    }

    public function test_v1_login_intent_binds_application_for_existing_legacy_session(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test/auth/callback');
        config()->set('services.workos.return_to', 'https://latte.test');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $user = new User;
        $user->forceFill([
            'user_id' => 123,
            'user_type_id' => 1,
            'name' => 'local-admin',
            'email' => 'local-admin@example.test',
            'is_active' => true,
        ]);
        $user->exists = true;

        $this->actingAs($user);

        $response = $this
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/login-intents', [
                'redirect_to' => 'https://latte.test/dashboard',
            ]);

        $response
            ->assertOk()
            ->assertSessionHas('pane_v1_application_id', config('services.latte.application_id'))
            ->assertSessionHas('pane_v1_application_session_version');
    }

    public function test_v1_login_intent_rejects_untrusted_redirect(): void
    {
        config()->set('services.workos.return_to', 'https://latte.test');
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $response = $this
            ->withHeader('X-Request-Id', 'not-a-uuid')
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/login-intents', [
                'redirect_to' => 'https://evil.test/dashboard',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'redirect_not_allowed')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']])
            ->assertSessionMissing('workos_state')
            ->assertSessionMissing('workos_intended_url');

        $this->assertTrue(Str::isUuid($response->json('error.request_id')));
        $this->assertSame($response->json('error.request_id'), $response->headers->get('X-Request-Id'));
        $this->assertNull($response->json('message'));
    }

    public function test_v1_login_intent_rejects_unregistered_redirect_path_on_allowed_origin(): void
    {
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $response = $this
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/login-intents', [
                'redirect_to' => 'https://latte.test/settings',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'redirect_not_allowed')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']])
            ->assertSessionMissing('workos_state')
            ->assertSessionMissing('workos_intended_url');
    }

    public function test_v1_login_intent_rejects_malformed_redirect(): void
    {
        config()->set('services.latte.frontend_url', 'https://latte.test');

        $response = $this
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/login-intents', [
                'redirect_to' => '/dashboard',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']])
            ->assertSessionMissing('workos_state')
            ->assertSessionMissing('workos_intended_url');

        $this->assertTrue(Str::isUuid($response->json('error.request_id')));
        $this->assertNull($response->json('message'));
    }

    public function test_v1_login_intent_accepts_pane_admin_invitation_token_for_callback_activation(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $actor = User::query()->create([
            'user_type_id' => User::PANE_ADMINISTRATOR_USER_TYPE_ID,
            'name' => 'Root Admin',
            'email' => 'root@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $create = $this
            ->withCsrfToken()
            ->actingAs($actor)
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/installation/pane-admin-invitations', [
                'email' => 'Invited.Admin@Example.COM',
            ])
            ->assertCreated();

        $invitationUrl = (string) $create->json('meta.invitation_url');
        $query = [];
        parse_str((string) parse_url($invitationUrl, PHP_URL_QUERY), $query);

        $this->assertIsString($query['invitation_token'] ?? null);

        $token = $query['invitation_token'];
        $invitation = PaneAdminInvitation::query()->where('email', 'invited.admin@example.com')->firstOrFail();

        $this->app['auth']->guard()->logout();

        $intent = $this
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/login-intents', [
                'redirect_to' => 'https://latte.test/dashboard',
                'invitation_token' => $token,
            ]);

        $intent
            ->assertOk()
            ->assertSessionHas('pane_admin_invitation_token_hash', hash('sha256', $token));

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [
                    'id' => 'user_invited',
                    'email' => 'invited.admin@example.com',
                    'email_verified' => true,
                    'first_name' => 'Invited',
                    'last_name' => 'Admin',
                ],
                'session_id' => 'session_123',
                'organization_id' => 'org_123',
                'authentication_method' => 'sso',
            ]),
        ]);

        $callback = $this
            ->withSession(array_merge($this->v1ApplicationSession(), [
                'workos_state' => $intent->json('data.state'),
                'workos_intended_url' => 'https://latte.test/dashboard',
                'pane_admin_invitation_token_hash' => hash('sha256', $token),
            ]))
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/callback', [
                'code' => 'code_123',
                'state' => $intent->json('data.state'),
            ]);

        $callback
            ->assertOk()
            ->assertSessionMissing('pane_admin_invitation_token_hash')
            ->assertJsonPath('data.user.attributes.email', 'invited.admin@example.com')
            ->assertJsonPath('data.membership.attributes.role', 'organization_administrator');

        $accepted = User::query()->where('email', 'invited.admin@example.com')->firstOrFail();

        $this->assertTrue($accepted->isPaneAdministrator());
        $this->assertSame(PaneAdminInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertStringNotContainsString($token, $accepted->toJson());
    }

    public function test_v1_callback_rejects_uninvited_workos_identity_without_creating_user(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $application = app(ApplicationRegistryService::class)->configuredLatteApplication();

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [
                    'id' => 'user_uninvited',
                    'email' => 'uninvited@example.com',
                    'email_verified' => true,
                ],
                'session_id' => 'session_123',
                'organization_id' => 'org_123',
                'authentication_method' => 'sso',
            ]),
        ]);

        $response = $this
            ->withSession(array_merge($this->v1ApplicationSession($application), [
                'workos_state' => 'expected_state',
            ]))
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/callback', [
                'code' => 'code_123',
                'state' => 'expected_state',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'membership_required')
            ->assertJsonPath('error.message', 'An active organization membership or invitation is required.');

        $this->assertFalse(User::query()->where('email', 'uninvited@example.com')->exists());
    }

    public function test_v1_callback_does_not_sync_existing_non_member_when_access_is_denied(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $application = app(ApplicationRegistryService::class)->configuredLatteApplication();
        $user = User::query()->create([
            'user_type_id' => User::STANDARD_USER_TYPE_ID,
            'name' => 'Original Name',
            'email' => 'existing@example.com',
            'password' => 'password',
            'workos_organization_id' => 'original_organization',
            'details' => ['source' => 'original'],
            'is_active' => false,
        ]);

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [
                    'id' => 'user_existing',
                    'email' => 'existing@example.com',
                    'email_verified' => true,
                    'first_name' => 'Changed',
                    'last_name' => 'Name',
                ],
                'session_id' => 'session_123',
                'organization_id' => 'changed_organization',
                'authentication_method' => 'sso',
            ]),
        ]);

        $this
            ->withSession(array_merge($this->v1ApplicationSession($application), [
                'workos_state' => 'expected_state',
            ]))
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/callback', [
                'code' => 'code_123',
                'state' => 'expected_state',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'membership_required');

        $user->refresh();

        $this->assertSame('Original Name', $user->name);
        $this->assertNull($user->workos_id);
        $this->assertSame('original_organization', $user->workos_organization_id);
        $this->assertSame(['source' => 'original'], $user->details);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->last_login_at);
    }

    public function test_v1_callback_does_not_sync_non_admin_when_burro_access_is_denied(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://burro.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');

        $application = ApplicationRegistration::query()->create([
            'name' => 'Burro',
            'kind' => ApplicationRegistration::KIND_BURRO,
            'organization_id' => null,
            'trusted_origin' => 'https://burro.test',
            'redirect_uris' => ['https://burro.test/dashboard'],
            'status' => ApplicationRegistration::STATUS_ACTIVE,
        ]);
        $user = User::query()->create([
            'user_type_id' => User::STANDARD_USER_TYPE_ID,
            'name' => 'Original Name',
            'email' => 'existing@example.com',
            'password' => 'password',
            'details' => ['source' => 'original'],
            'is_active' => true,
        ]);

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [
                    'id' => 'user_existing',
                    'email' => 'existing@example.com',
                    'email_verified' => true,
                    'first_name' => 'Changed',
                    'last_name' => 'Name',
                ],
                'session_id' => 'session_123',
                'organization_id' => 'changed_organization',
                'authentication_method' => 'sso',
            ]),
        ]);

        $this
            ->withSession(array_merge($this->v1ApplicationSession($application), [
                'workos_state' => 'expected_state',
            ]))
            ->withHeader('Origin', 'https://burro.test')
            ->postJson('/api/v1/auth/callback', [
                'code' => 'code_123',
                'state' => 'expected_state',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'permission_denied');

        $user->refresh();

        $this->assertSame('Original Name', $user->name);
        $this->assertNull($user->workos_id);
        $this->assertSame(['source' => 'original'], $user->details);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->last_login_at);
    }

    public function test_v1_callback_does_not_sync_suspended_pane_admin_with_active_membership(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $application = app(ApplicationRegistryService::class)->configuredLatteApplication();
        $organization = $application->organization()->firstOrFail();
        $user = User::query()->create([
            'user_type_id' => User::PANE_ADMINISTRATOR_USER_TYPE_ID,
            'name' => 'Suspended Administrator',
            'email' => 'suspended-admin@example.com',
            'password' => 'password',
            'workos_organization_id' => 'original_organization',
            'details' => ['source' => 'original'],
            'is_active' => false,
        ]);
        app(OrganizationTenancyService::class)->addOrReactivateMembership(
            $organization,
            $user,
            OrganizationMembership::ROLE_USER,
        );

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [
                    'id' => 'user_suspended_admin',
                    'email' => 'suspended-admin@example.com',
                    'email_verified' => true,
                    'first_name' => 'Changed',
                    'last_name' => 'Name',
                ],
                'session_id' => 'session_123',
                'organization_id' => 'changed_organization',
                'authentication_method' => 'sso',
            ]),
        ]);

        $this
            ->withSession(array_merge($this->v1ApplicationSession($application), [
                'workos_state' => 'expected_state',
            ]))
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/callback', [
                'code' => 'code_123',
                'state' => 'expected_state',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'permission_denied')
            ->assertJsonPath('error.message', 'Pane account is inactive.');

        $user->refresh();

        $this->assertSame('Suspended Administrator', $user->name);
        $this->assertNull($user->workos_id);
        $this->assertSame('original_organization', $user->workos_organization_id);
        $this->assertSame(['source' => 'original'], $user->details);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->last_login_at);
    }

    public function test_v1_callback_accepts_organization_invitation_and_reactivates_suspended_membership(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $application = app(ApplicationRegistryService::class)->configuredLatteApplication();
        $organization = $application->organization()->firstOrFail();
        $tenancy = app(OrganizationTenancyService::class);
        $administrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $member = User::query()->create([
            'user_type_id' => User::STANDARD_USER_TYPE_ID,
            'name' => 'Suspended Member',
            'email' => 'member@example.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        $tenancy->addOrReactivateMembership($organization, $administrator, OrganizationMembership::ROLE_ADMINISTRATOR);
        $membership = $tenancy->addOrReactivateMembership($organization, $member, OrganizationMembership::ROLE_USER);
        $tenancy->suspendMembership($membership);

        $result = app(OrganizationInvitationService::class)->inviteOrganizationMember(
            $administrator,
            $organization,
            'Member@Example.COM',
            OrganizationMembership::ROLE_ADMINISTRATOR
        );
        $token = $result['token'];
        /** @var OrganizationInvitation $invitation */
        $invitation = $result['invitation'];

        $intent = $this
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/login-intents', [
                'redirect_to' => 'https://latte.test/dashboard',
                'invitation_token' => $token,
            ]);

        $intent
            ->assertOk()
            ->assertSessionHas('pane_admin_invitation_token_hash', hash('sha256', $token));

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [
                    'id' => 'user_member',
                    'email' => 'member@example.com',
                    'email_verified' => true,
                    'first_name' => 'Invited',
                    'last_name' => 'Member',
                ],
                'session_id' => 'session_123',
                'organization_id' => 'org_123',
                'authentication_method' => 'sso',
            ]),
        ]);

        $callback = $this
            ->withSession(array_merge($this->v1ApplicationSession($application), [
                'workos_state' => $intent->json('data.state'),
                'workos_intended_url' => 'https://latte.test/dashboard',
                'pane_admin_invitation_token_hash' => hash('sha256', $token),
            ]))
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/callback', [
                'code' => 'code_123',
                'state' => $intent->json('data.state'),
            ]);

        $callback
            ->assertOk()
            ->assertSessionMissing('pane_admin_invitation_token_hash')
            ->assertJsonPath('data.organization.id', $organization->organization_id)
            ->assertJsonPath('data.membership.id', $membership->membership_id)
            ->assertJsonPath('data.membership.attributes.role', OrganizationMembership::ROLE_ADMINISTRATOR);

        $this->assertSame(OrganizationInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertSame(OrganizationMembership::STATUS_ACTIVE, $membership->fresh()->status);
        $this->assertStringNotContainsString($token, $callback->getContent());
    }

    public function test_v1_callback_does_not_consume_organization_invitation_for_suspended_pane_admin(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $application = app(ApplicationRegistryService::class)->configuredLatteApplication();
        $organization = $application->organization()->firstOrFail();
        $tenancy = app(OrganizationTenancyService::class);
        $administrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $suspendedPaneAdmin = User::query()->create([
            'user_type_id' => User::PANE_ADMINISTRATOR_USER_TYPE_ID,
            'name' => 'Suspended Administrator',
            'email' => 'suspended-admin@example.com',
            'password' => 'password',
            'details' => ['source' => 'original'],
            'is_active' => false,
        ]);
        $tenancy->addOrReactivateMembership(
            $organization,
            $administrator,
            OrganizationMembership::ROLE_ADMINISTRATOR,
        );
        $result = app(OrganizationInvitationService::class)->inviteOrganizationMember(
            $administrator,
            $organization,
            $suspendedPaneAdmin->email,
            OrganizationMembership::ROLE_USER,
        );
        $token = $result['token'];
        /** @var OrganizationInvitation $invitation */
        $invitation = $result['invitation'];

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [
                    'id' => 'user_suspended_admin',
                    'email' => 'suspended-admin@example.com',
                    'email_verified' => true,
                    'first_name' => 'Changed',
                    'last_name' => 'Name',
                ],
                'session_id' => 'session_123',
                'organization_id' => 'org_123',
                'authentication_method' => 'sso',
            ]),
        ]);

        $this
            ->withSession(array_merge($this->v1ApplicationSession($application), [
                'workos_state' => 'expected_state',
                'pane_admin_invitation_token_hash' => hash('sha256', $token),
            ]))
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/callback', [
                'code' => 'code_123',
                'state' => 'expected_state',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'permission_denied')
            ->assertJsonPath('error.message', 'Pane account is inactive.');

        $suspendedPaneAdmin->refresh();

        $this->assertSame('Suspended Administrator', $suspendedPaneAdmin->name);
        $this->assertNull($suspendedPaneAdmin->workos_id);
        $this->assertSame(['source' => 'original'], $suspendedPaneAdmin->details);
        $this->assertNull($suspendedPaneAdmin->last_login_at);
        $this->assertNull($organization->activeMembershipFor($suspendedPaneAdmin));
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $invitation->fresh()->status);
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_v1_session_returns_latte_session_payload(): void
    {
        $requestId = (string) Str::uuid();

        config()->set('services.workos.return_to', 'https://latte.test');
        config()->set('services.latte.application_id', '00000000-0000-4000-8000-000000000101');
        config()->set('services.latte.organization_id', '00000000-0000-4000-8000-000000000102');
        config()->set('services.latte.frontend_url', 'https://latte.test');

        $user = new User;
        $user->forceFill([
            'user_id' => 123,
            'user_type_id' => 1,
            'name' => 'local-admin',
            'email' => 'local-admin@example.test',
            'is_active' => true,
        ]);
        $user->exists = true;

        $this->actingAs($user);

        $response = $this
            ->withHeader('X-Request-Id', $requestId)
            ->withV1ApplicationSession()
            ->getJson('/api/v1/session');

        $response
            ->assertOk()
            ->assertHeader('X-Request-Id', $requestId)
            ->assertJsonPath('data.mode', 'latte')
            ->assertJsonPath('data.application.id', '00000000-0000-4000-8000-000000000101')
            ->assertJsonPath('data.application.attributes.kind', 'latte')
            ->assertJsonPath('data.application.attributes.trusted_origin', 'https://latte.test')
            ->assertJsonPath('data.organization.id', '00000000-0000-4000-8000-000000000102')
            ->assertJsonPath('data.membership.attributes.role', 'organization_administrator')
            ->assertJsonPath('meta.request_id', $requestId)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'type', 'attributes' => ['email', 'name']],
                    'application' => ['id', 'type', 'attributes' => ['redirect_uris', 'status']],
                    'organization' => ['id', 'type', 'attributes' => ['name', 'slug', 'status', 'database_limit']],
                    'membership' => ['id', 'type', 'attributes' => ['role', 'status']],
                ],
                'meta' => ['request_id'],
            ]);

        $this->assertTrue(Str::isUuid($response->json('data.user.id')));
        $this->assertTrue(Str::isUuid($response->json('data.membership.id')));
    }

    public function test_v1_session_rejects_authenticated_session_without_bound_application(): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => 123,
            'user_type_id' => 1,
            'name' => 'local-admin',
            'email' => 'local-admin@example.test',
            'is_active' => true,
        ]);
        $user->exists = true;

        $this->actingAs($user);

        $response = $this->getJson('/api/v1/session');

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    public function test_v1_session_reloads_application_bound_during_login(): void
    {
        config()->set('services.latte.application_id', '00000000-0000-4000-8000-000000000201');
        config()->set('services.latte.organization_id', '00000000-0000-4000-8000-000000000202');
        config()->set('services.latte.frontend_url', 'https://updated-latte.test/app');
        config()->set('services.latte.redirect_uris', ['https://updated-latte.test/dashboard']);

        $user = new User;
        $user->forceFill([
            'user_id' => 123,
            'user_type_id' => 1,
            'name' => 'local-admin',
            'email' => 'local-admin@example.test',
            'is_active' => true,
        ]);
        $user->exists = true;

        $this->actingAs($user);

        $response = $this
            ->withV1ApplicationSession()
            ->getJson('/api/v1/session');

        $response
            ->assertOk()
            ->assertJsonPath('data.application.id', '00000000-0000-4000-8000-000000000201')
            ->assertJsonPath('data.application.attributes.trusted_origin', 'https://updated-latte.test')
            ->assertJsonPath('data.application.attributes.redirect_uris.0', 'https://updated-latte.test/dashboard')
            ->assertJsonPath('data.organization.id', '00000000-0000-4000-8000-000000000202');
    }

    public function test_v1_session_rejects_stale_bound_application(): void
    {
        ApplicationRegistration::query()
            ->whereKey('00000000-0000-4000-8000-000000000201')
            ->delete();

        config()->set('services.latte.application_id', '00000000-0000-4000-8000-000000000301');
        config()->set('services.latte.frontend_url', 'https://current-latte.test');

        $user = new User;
        $user->forceFill([
            'user_id' => 123,
            'user_type_id' => 1,
            'name' => 'local-admin',
            'email' => 'local-admin@example.test',
            'is_active' => true,
        ]);
        $user->exists = true;

        $this->actingAs($user);

        $this
            ->withSession([
                'pane_v1_application_id' => '00000000-0000-4000-8000-000000000201',
                'pane_v1_application_session_version' => '00000000-0000-4000-8000-000000000202',
            ])
            ->getJson('/api/v1/session')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed');
    }

    public function test_v1_session_origin_validation_reloads_bound_application(): void
    {
        config()->set('services.latte.application_id', '00000000-0000-4000-8000-000000000201');
        config()->set('services.latte.frontend_url', 'https://current-latte.test');

        $user = new User;
        $user->forceFill([
            'user_id' => 123,
            'user_type_id' => 1,
            'name' => 'local-admin',
            'email' => 'local-admin@example.test',
            'is_active' => true,
        ]);
        $user->exists = true;

        $this->actingAs($user);

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://previous-latte.test')
            ->getJson('/api/v1/session')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed');

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://current-latte.test')
            ->getJson('/api/v1/session')
            ->assertOk()
            ->assertJsonPath('data.application.id', '00000000-0000-4000-8000-000000000201');
    }

    public function test_v1_csrf_cookie_rejects_missing_origin(): void
    {
        $response = $this->postJson('/api/v1/csrf-cookie');

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);

        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('error.request_id'));
    }

    public function test_v1_login_intent_rejects_unregistered_origin(): void
    {
        config()->set('services.latte.frontend_url', 'https://latte.test');

        $response = $this
            ->withHeader('Origin', 'https://evil.test')
            ->postJson('/api/v1/auth/login-intents', [
                'redirect_to' => 'https://latte.test/dashboard',
            ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']])
            ->assertSessionMissing('workos_state')
            ->assertSessionMissing('workos_intended_url');
    }

    public function test_v1_session_uses_normalized_latte_origin_for_application_projection(): void
    {
        config()->set('services.workos.return_to', 'https://latte.test/dashboard');
        config()->set('services.latte.frontend_url', 'https://LATTE.test:443/app');
        config()->set('services.latte.redirect_uris', ['https://LATTE.test:443/auth/callback']);

        $user = new User;
        $user->forceFill([
            'user_id' => 123,
            'user_type_id' => 1,
            'name' => 'local-admin',
            'email' => 'local-admin@example.test',
            'is_active' => true,
        ]);
        $user->exists = true;

        $this->actingAs($user);

        $response = $this
            ->withV1ApplicationSession()
            ->getJson('/api/v1/session');

        $response
            ->assertOk()
            ->assertJsonPath('data.application.attributes.trusted_origin', 'https://latte.test')
            ->assertJsonPath('data.application.attributes.redirect_uris.0', 'https://latte.test/auth/callback');
    }

    public function test_v1_session_rejects_mismatched_origin_when_supplied(): void
    {
        config()->set('services.latte.frontend_url', 'https://latte.test');

        $user = new User;
        $user->forceFill([
            'user_id' => 123,
            'user_type_id' => 1,
            'name' => 'local-admin',
            'email' => 'local-admin@example.test',
            'is_active' => true,
        ]);
        $user->exists = true;

        $this->actingAs($user);

        $response = $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://other.test')
            ->getJson('/api/v1/session');

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    public function test_v1_session_returns_error_envelope_when_not_authenticated(): void
    {
        $requestId = (string) Str::uuid();

        $response = $this
            ->withHeader('X-Request-Id', $requestId)
            ->getJson('/api/v1/session');

        $response
            ->assertUnauthorized()
            ->assertHeader('X-Request-Id', $requestId)
            ->assertJsonPath('error.code', 'authentication_required')
            ->assertJsonPath('error.request_id', $requestId)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);

        $this->assertNull($response->json('status'));
        $this->assertNull($response->json('data'));
    }

    public function test_v1_destroy_session_returns_no_content(): void
    {
        $requestId = (string) Str::uuid();

        $user = new User;
        $user->forceFill([
            'user_id' => 123,
            'user_type_id' => 1,
            'name' => 'local-admin',
            'email' => 'local-admin@example.test',
            'is_active' => true,
        ]);
        $user->exists = true;

        $this->withCsrfToken()->actingAs($user);

        $response = $this
            ->withV1ApplicationSession()
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('Origin', 'https://latte.localhost')
            ->deleteJson('/api/v1/session');

        $response
            ->assertNoContent()
            ->assertHeader('X-Request-Id', $requestId);
    }

    public function test_v1_destroy_session_rejects_authenticated_session_without_bound_application(): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => 123,
            'user_type_id' => 1,
            'name' => 'local-admin',
            'email' => 'local-admin@example.test',
            'is_active' => true,
        ]);
        $user->exists = true;

        $this->withCsrfToken()->actingAs($user);

        $response = $this
            ->withHeader('Origin', 'https://latte.localhost')
            ->deleteJson('/api/v1/session');

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    public function test_v1_destroy_session_rejects_missing_origin_before_csrf(): void
    {
        $user = new User;
        $user->forceFill([
            'user_id' => 123,
            'user_type_id' => 1,
            'name' => 'local-admin',
            'email' => 'local-admin@example.test',
            'is_active' => true,
        ]);
        $user->exists = true;

        $this->actingAs($user);

        $response = $this->deleteJson('/api/v1/session');

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $response = $this
            ->withSession(['workos_state' => 'expected_state'])
            ->get('/auth/callback?code=code_123&state=wrong_state');

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
    }

    public function test_json_callback_rejects_invalid_state(): void
    {
        $response = $this
            ->withSession(['workos_state' => 'expected_state'])
            ->postJson('/auth/callback', [
                'code' => 'code_123',
                'state' => 'wrong_state',
            ]);

        $response
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJson(['message' => 'Invalid WorkOS state.']);
    }

    public function test_json_callback_preserves_provider_error_description_for_legacy_route(): void
    {
        $response = $this->postJson('/auth/callback', [
            'error' => 'access_denied',
            'error_description' => 'Connection conn_123 failed for private@example.test',
        ]);

        $response
            ->assertBadRequest()
            ->assertJson(['message' => 'Connection conn_123 failed for private@example.test']);
    }

    public function test_v1_json_callback_uses_safe_message_for_provider_errors(): void
    {
        $requestId = (string) Str::uuid();

        $response = $this
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/auth/callback', [
                'error' => 'access_denied',
                'error_description' => 'Connection conn_123 failed for private@example.test',
                'state' => str_repeat('s', 32),
            ]);

        $response
            ->assertBadRequest()
            ->assertHeader('X-Request-Id', $requestId)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.message', 'The WorkOS callback was rejected.')
            ->assertJsonPath('error.request_id', $requestId);

        $this->assertStringNotContainsString('conn_123', $response->getContent());
        $this->assertStringNotContainsString('private@example.test', $response->getContent());
        $this->assertNull($response->json('error.details'));
        $this->assertNull($response->json('message'));
    }

    public function test_v1_json_callback_rejects_invalid_state_with_error_envelope(): void
    {
        $requestId = (string) Str::uuid();

        $response = $this
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('Origin', 'https://latte.localhost')
            ->withSession(['workos_state' => 'expected_state'])
            ->postJson('/api/v1/auth/callback', [
                'code' => 'code_123',
                'state' => 'wrong_state',
            ]);

        $response
            ->assertBadRequest()
            ->assertHeader('X-Request-Id', $requestId)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.message', 'Invalid WorkOS state.')
            ->assertJsonPath('error.request_id', $requestId)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);

        $this->assertTrue(Str::isUuid($response->json('error.request_id')));
        $this->assertNull($response->json('message'));
    }

    public function test_v1_json_callback_rejects_missing_workos_email_with_error_envelope(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test');

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [],
            ]),
        ]);

        $response = $this
            ->withHeader('Origin', 'https://latte.localhost')
            ->withSession(['workos_state' => 'expected_state'])
            ->postJson('/api/v1/auth/callback', [
                'code' => 'code_123',
                'state' => 'expected_state',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'WorkOS did not return a user email.')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);

        $this->assertTrue(Str::isUuid($response->json('error.request_id')));
        $this->assertNull($response->json('error.details'));
        $this->assertNull($response->json('message'));

        Http::assertSentCount(1);
    }

    public function test_user_endpoint_returns_unauthorized_when_not_authenticated(): void
    {
        $response = $this->getJson('/auth/user');

        $response
            ->assertStatus(Response::HTTP_UNAUTHORIZED)
            ->assertJsonPath('data.message', 'Unauthenticated.');
    }

    public function test_json_callback_accepts_state_from_pane_cookie(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test');

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [],
            ]),
        ]);

        $response = $this
            ->withCredentials()
            ->withCookie('pane_workos_state', 'expected_state')
            ->postJson('/auth/callback', [
                'code' => 'code_123',
                'state' => 'expected_state',
            ]);

        $response
            ->assertBadRequest()
            ->assertJson(['message' => 'WorkOS did not return a user email.']);

        Http::assertSentCount(1);
    }

    public function test_json_callback_ignores_null_error_fields(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test');

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [],
            ]),
        ]);

        $response = $this
            ->withCredentials()
            ->withCookie('pane_workos_state', 'expected_state')
            ->postJson('/auth/callback', [
                'code' => 'code_123',
                'state' => 'expected_state',
                'error' => null,
                'error_description' => null,
            ]);

        $response
            ->assertBadRequest()
            ->assertJson(['message' => 'WorkOS did not return a user email.']);

        Http::assertSentCount(1);
    }

    public function test_json_callback_does_not_store_workos_bearer_tokens_in_session(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test');

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [
                    'id' => 'user_123',
                    'email' => 'issue34@example.com',
                    'email_verified' => true,
                ],
                'access_token' => 'access_token',
                'refresh_token' => 'refresh_token',
                'session_id' => 'session_123',
                'organization_id' => 'org_123',
            ]),
        ]);

        $response = $this
            ->withSession(['workos_state' => 'expected_state'])
            ->postJson('/auth/callback', [
                'code' => 'code_123',
                'state' => 'expected_state',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.email', 'issue34@example.com')
            ->assertJsonPath('workos_organization_id', 'org_123')
            ->assertSessionHas('workos_completed_state', 'expected_state')
            ->assertSessionHas('workos_session_id', 'session_123')
            ->assertSessionHas('workos_organization_id', 'org_123')
            ->assertSessionMissing([
                'workos_access_token',
                'workos_refresh_token',
            ]);

        Http::assertSentCount(1);
    }
}
