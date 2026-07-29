<?php

namespace Tests\Feature\PaneAdmin;

use App\Models\PaneAdminInvitation;
use App\Models\User;
use Illuminate\Http\Response;
use Tests\TestCase;

class PaneAdminInvitationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PaneAdminInvitation::query()->delete();
        User::query()->delete();
    }

    public function test_pane_admin_can_create_list_and_revoke_invitations_through_v1_api(): void
    {
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($actor);

        $create = $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/installation/pane-admin-invitations', [
                'email' => 'Invited.Admin@Example.COM',
            ]);

        $create
            ->assertCreated()
            ->assertHeader('X-Request-Id')
            ->assertHeader('ETag')
            ->assertJsonPath('data.type', 'invitation')
            ->assertJsonPath('data.attributes.scope', 'installation')
            ->assertJsonPath('data.attributes.role', 'pane_administrator')
            ->assertJsonPath('data.attributes.email', 'invited.admin@example.com')
            ->assertJsonPath('data.attributes.status', PaneAdminInvitation::STATUS_PENDING);

        $this->assertNull($create->json('data.attributes.token'));
        $this->assertNull($create->json('meta.invitation_token'));

        $invitationId = $create->json('data.id');

        $list = $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson('/api/v1/installation/pane-admin-invitations');

        $list
            ->assertOk()
            ->assertJsonPath('data.0.id', $invitationId)
            ->assertJsonPath('meta.page.has_more', false);

        $revoke = $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->deleteJson("/api/v1/installation/pane-admin-invitations/$invitationId");

        $revoke->assertNoContent();

        $this->assertSame(
            PaneAdminInvitation::STATUS_REVOKED,
            PaneAdminInvitation::query()->findOrFail($invitationId)->status
        );
    }

    public function test_non_pane_admin_cannot_create_pane_admin_invitation(): void
    {
        $this->withCsrfToken()->actingAs($this->makePaneUser(User::STANDARD_USER_TYPE_ID));

        $response = $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/installation/pane-admin-invitations', [
                'email' => 'invited.admin@example.com',
            ]);

        $response
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('error.code', 'permission_denied');
    }
}
