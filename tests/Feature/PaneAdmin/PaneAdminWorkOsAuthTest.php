<?php

namespace Tests\Feature\PaneAdmin;

use App\Models\PaneAdminInvitation;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaneAdminWorkOsAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PaneAdminInvitation::query()->delete();
        User::query()->delete();
    }

    public function test_workos_callback_does_not_reactivate_suspended_pane_administrator(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test');

        $administrator = User::query()->create([
            'user_type_id' => User::PANE_ADMINISTRATOR_USER_TYPE_ID,
            'name' => 'Suspended Admin',
            'email' => 'suspended@example.com',
            'password' => 'password',
            'workos_id' => 'user_suspended',
            'is_active' => false,
        ]);

        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [
                    'id' => 'user_suspended',
                    'email' => 'suspended@example.com',
                    'email_verified' => true,
                ],
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
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJson(['message' => 'Pane account is inactive.']);

        $this->assertFalse((bool) $administrator->fresh()->is_active);
        $this->assertGuest();
        Http::assertSentCount(1);
    }
}
