<?php

namespace Tests\Feature;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
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
