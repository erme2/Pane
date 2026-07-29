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
            ->assertJsonPath('meta.invitation_url', fn (string $url): bool => str_starts_with($url, 'https://latte.localhost/auth/login?'))
            ->assertJsonPath('data.type', 'invitation')
            ->assertJsonPath('data.attributes.scope', 'installation')
            ->assertJsonPath('data.attributes.role', 'pane_administrator')
            ->assertJsonPath('data.attributes.email', 'invited.admin@example.com')
            ->assertJsonPath('data.attributes.status', PaneAdminInvitation::STATUS_PENDING);

        $this->assertNull($create->json('data.attributes.token'));

        $invitationId = $create->json('data.id');
        $invitationUrl = (string) $create->json('meta.invitation_url');
        $invitationQuery = [];
        parse_str((string) parse_url($invitationUrl, PHP_URL_QUERY), $invitationQuery);

        $this->assertIsString($invitationQuery['invitation_token'] ?? null);
        $this->assertSame(
            hash('sha256', $invitationQuery['invitation_token']),
            PaneAdminInvitation::query()->findOrFail($invitationId)->token_hash
        );

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
            ->withHeader('If-Match', (string) $create->headers->get('ETag'))
            ->deleteJson("/api/v1/installation/pane-admin-invitations/$invitationId");

        $revoke->assertNoContent();

        $this->assertSame(
            PaneAdminInvitation::STATUS_REVOKED,
            PaneAdminInvitation::query()->findOrFail($invitationId)->status
        );
    }

    public function test_list_uses_opaque_cursor_pagination(): void
    {
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($actor);

        foreach (['first@example.com', 'second@example.com', 'third@example.com'] as $email) {
            $this
                ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
                ->withHeader('Origin', 'https://latte.localhost')
                ->postJson('/api/v1/installation/pane-admin-invitations', ['email' => $email])
                ->assertCreated();
        }

        $firstPage = $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson('/api/v1/installation/pane-admin-invitations?'.http_build_query([
                'page' => ['limit' => 1],
            ], '', '&', PHP_QUERY_RFC3986));

        $firstPage
            ->assertOk()
            ->assertJsonPath('meta.page.has_more', true);

        $firstId = $firstPage->json('data.0.id');
        $cursor = $firstPage->json('meta.page.next_cursor');

        $this->assertIsString($firstId);
        $this->assertIsString($cursor);
        $this->assertNotSame($firstId, $cursor);

        $secondPage = $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson('/api/v1/installation/pane-admin-invitations?'.http_build_query([
                'page' => ['limit' => 1, 'cursor' => $cursor],
            ], '', '&', PHP_QUERY_RFC3986));

        $secondPage
            ->assertOk()
            ->assertJsonPath('meta.page.has_more', true);

        $this->assertIsString($secondPage->json('data.0.id'));
        $this->assertNotSame($firstId, $secondPage->json('data.0.id'));

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson('/api/v1/installation/pane-admin-invitations?'.http_build_query([
                'page' => ['cursor' => 'not-a-valid-cursor'],
            ], '', '&', PHP_QUERY_RFC3986))
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('error.code', 'invalid_cursor');
    }

    public function test_revoke_requires_current_strong_if_match(): void
    {
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($actor);

        $create = $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/installation/pane-admin-invitations', [
                'email' => 'invited.admin@example.com',
            ])
            ->assertCreated();

        $invitationId = $create->json('data.id');

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->deleteJson("/api/v1/installation/pane-admin-invitations/$invitationId")
            ->assertStatus(Response::HTTP_PRECONDITION_REQUIRED)
            ->assertJsonPath('error.code', 'precondition_required');

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', 'W/"revision_42"')
            ->deleteJson("/api/v1/installation/pane-admin-invitations/$invitationId")
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('error.code', 'invalid_request');

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', '"revision_42"')
            ->deleteJson("/api/v1/installation/pane-admin-invitations/$invitationId")
            ->assertStatus(Response::HTTP_PRECONDITION_FAILED)
            ->assertJsonPath('error.code', 'version_conflict');

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', (string) $create->headers->get('ETag'))
            ->deleteJson("/api/v1/installation/pane-admin-invitations/$invitationId")
            ->assertNoContent();
    }

    public function test_create_rejects_unsupported_fields(): void
    {
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($actor);

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/installation/pane-admin-invitations', [
                'email' => 'invited.admin@example.com',
                'expires_in_seconds' => 999999,
                'role' => 'organization_administrator',
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, PaneAdminInvitation::query()->count());
    }

    public function test_revoke_rejects_malformed_invitation_identifier(): void
    {
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($actor);

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', '"revision_42"')
            ->deleteJson('/api/v1/installation/pane-admin-invitations/not-a-uuid')
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('error.code', 'invalid_identifier');
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
