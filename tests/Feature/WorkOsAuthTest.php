<?php

namespace Tests\Feature;

use Illuminate\Http\Response;
use Tests\TestCase;

class WorkOsAuthTest extends TestCase
{
    public function test_login_redirects_to_workos(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://pane.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');

        $response = $this->get('/auth/login?redirect_to=https://pane.test/dashboard');

        $response->assertRedirectContains('https://api.workos.com/user_management/authorize');
        $response->assertSessionHas('workos_state');
        $response->assertSessionHas('workos_intended_url', 'https://pane.test/dashboard');
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $response = $this
            ->withSession(['workos_state' => 'expected_state'])
            ->get('/auth/callback?code=code_123&state=wrong_state');

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
    }
}
