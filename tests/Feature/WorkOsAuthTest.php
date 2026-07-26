<?php

namespace Tests\Feature;

use App\Models\User;
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
            ->postJson('/api/v1/csrf-cookie');

        $response
            ->assertNoContent()
            ->assertHeader('X-Request-Id', $requestId);
    }

    public function test_v1_login_intent_returns_versioned_payload(): void
    {
        $requestId = (string) Str::uuid();

        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test/auth/callback');
        config()->set('services.workos.return_to', 'https://latte.test');
        config()->set('services.workos.provider', 'authkit');

        $response = $this
            ->withHeader('X-Request-Id', $requestId)
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
            ->assertSessionHas('workos_intended_url', 'https://latte.test/dashboard');

        $this->assertStringStartsWith(
            'https://api.workos.com/user_management/authorize?',
            $response->json('data.authorization_url')
        );
    }

    public function test_v1_login_intent_rejects_untrusted_redirect(): void
    {
        config()->set('services.workos.return_to', 'https://latte.test');
        config()->set('services.latte.frontend_url', 'https://latte.test');

        $response = $this
            ->withHeader('X-Request-Id', 'not-a-uuid')
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

    public function test_v1_login_intent_rejects_malformed_redirect(): void
    {
        $response = $this->postJson('/api/v1/auth/login-intents', [
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

    public function test_v1_session_uses_normalized_latte_origin_for_application_projection(): void
    {
        config()->set('services.workos.return_to', 'https://latte.test/dashboard');
        config()->set('services.latte.frontend_url', 'https://LATTE.test:443/app');

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
            ->deleteJson('/api/v1/session');

        $response
            ->assertNoContent()
            ->assertHeader('X-Request-Id', $requestId);
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

    public function test_v1_json_callback_rejects_invalid_state_with_error_envelope(): void
    {
        $requestId = (string) Str::uuid();

        $response = $this
            ->withHeader('X-Request-Id', $requestId)
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
