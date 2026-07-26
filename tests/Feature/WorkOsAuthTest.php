<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LatteApplicationConfig;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkOsAuthTest extends TestCase
{
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
            ->assertSessionMissing('pane_v1_application');

        $this->assertStringStartsWith(
            'https://api.workos.com/user_management/authorize?',
            $response->json('data.authorization_url')
        );
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

    public function test_v1_login_intent_rejects_invitation_token_until_activation_is_supported(): void
    {
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $response = $this
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/login-intents', [
                'redirect_to' => 'https://latte.test/dashboard',
                'invitation_token' => str_repeat('a', 32),
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'The invitation_token field is not supported.')
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']])
            ->assertSessionMissing('workos_state')
            ->assertSessionMissing('workos_intended_url');
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
            ->withSession(['pane_v1_application_id' => '00000000-0000-4000-8000-000000000201'])
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
            ->withSession(['pane_v1_application_id' => '00000000-0000-4000-8000-000000000201'])
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
            ->withSession(['pane_v1_application_id' => '00000000-0000-4000-8000-000000000201'])
            ->withHeader('Origin', 'https://previous-latte.test')
            ->getJson('/api/v1/session')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed');

        $this
            ->withSession(['pane_v1_application_id' => '00000000-0000-4000-8000-000000000201'])
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

        $response = $this->getJson('/api/v1/session');

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
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('Origin', 'https://latte.localhost')
            ->deleteJson('/api/v1/session');

        $response
            ->assertNoContent()
            ->assertHeader('X-Request-Id', $requestId);
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
