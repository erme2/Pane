<?php

namespace Tests\Feature\PaneAdmin;

use App\Mail\InvitationMail;
use App\Models\PaneAdminInvitation;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UserInvitationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        UserInvitation::query()->delete();
        PaneAdminInvitation::query()->delete();
        User::query()->delete();

        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);
    }

    public function test_admin_invites_lists_and_revokes_a_user(): void
    {
        $this->withCsrfToken()->actingAs($this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID));

        $create = $this->invite('Invited.User@Example.COM')
            ->assertCreated()
            ->assertHeader('ETag')
            ->assertJsonPath('data.type', 'invitation')
            ->assertJsonPath('data.attributes.role', 'user')
            ->assertJsonPath('data.attributes.email', 'invited.user@example.com')
            ->assertJsonPath('data.attributes.status', UserInvitation::STATUS_PENDING);

        $id = $create->json('data.id');
        $token = $this->tokenFromUrl((string) $create->json('meta.invitation_url'));

        $this->assertSame(hash('sha256', $token), UserInvitation::query()->findOrFail($id)->token_hash);
        $this->assertSame(0, PaneAdminInvitation::query()->count());

        $this->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.test')
            ->getJson('/api/v1/installation/user-invitations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('meta.page.has_more', false);

        $this->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.test')
            ->deleteJson("/api/v1/installation/user-invitations/$id")
            ->assertStatus(Response::HTTP_PRECONDITION_REQUIRED);

        $this->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', (string) $create->headers->get('ETag'))
            ->deleteJson("/api/v1/installation/user-invitations/$id")
            ->assertNoContent();

        $this->assertSame(UserInvitation::STATUS_REVOKED, UserInvitation::query()->findOrFail($id)->status);
    }

    public function test_a_standard_user_cannot_invite_list_or_revoke(): void
    {
        $this->withCsrfToken()->actingAs($this->makePaneUser(User::STANDARD_USER_TYPE_ID));

        $this->invite('someone@example.com')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'permission_denied');

        $this->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.test')
            ->getJson('/api/v1/installation/user-invitations')
            ->assertForbidden();

        $invitation = UserInvitation::query()->create([
            'email' => 'someone@example.com',
            'token_hash' => hash('sha256', 'token'),
            'status' => UserInvitation::STATUS_PENDING,
            'invited_by_user_id' => 1,
            'expires_at' => now()->addDay(),
        ]);

        $this->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.test')
            ->withHeader('If-Match', '"anything"')
            ->deleteJson('/api/v1/installation/user-invitations/'.$invitation->getKey())
            ->assertForbidden();

        $this->assertSame(UserInvitation::STATUS_PENDING, $invitation->fresh()->status);
        $this->assertSame(1, UserInvitation::query()->count());
    }

    public function test_inviting_an_active_user_is_a_conflict_and_extra_fields_are_rejected(): void
    {
        $this->withCsrfToken()->actingAs($this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID));
        $existing = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);

        $this->invite($existing->email)
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('error.code', 'operation_conflict');

        $this->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/installation/user-invitations', ['email' => 'a@example.com', 'role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, UserInvitation::query()->count());
    }

    public function test_accepting_a_user_invitation_creates_a_standard_user(): void
    {
        $this->withCsrfToken()->actingAs($this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID));
        $create = $this->invite('invited.user@example.com')->assertCreated();
        $token = $this->tokenFromUrl((string) $create->json('meta.invitation_url'));
        $this->app['auth']->guard()->logout();

        $this->completeLogin($token, 'invited.user@example.com')
            ->assertOk()
            ->assertJsonPath('data.user.attributes.email', 'invited.user@example.com')
            ->assertJsonPath('data.user.attributes.role', 'user');

        $user = User::query()->where('email', 'invited.user@example.com')->firstOrFail();

        $this->assertSame(User::STANDARD_USER_TYPE_ID, (int) $user->user_type_id);
        $this->assertFalse($user->isPaneAdministrator());
        $this->assertSame(UserInvitation::STATUS_ACCEPTED, UserInvitation::query()->firstOrFail()->status);
    }

    public function test_an_expired_or_mismatched_invitation_does_not_create_a_user(): void
    {
        $this->withCsrfToken()->actingAs($this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID));
        $token = $this->tokenFromUrl((string) $this->invite('late@example.com')->json('meta.invitation_url'));
        $this->app['auth']->guard()->logout();

        $this->completeLogin($token, 'someone.else@example.com')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invitation_email_mismatch');

        UserInvitation::query()->update(['expires_at' => now()->subMinute()]);

        $this->completeLogin($token, 'late@example.com')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invitation_expired');

        $this->assertSame(UserInvitation::STATUS_EXPIRED, UserInvitation::query()->firstOrFail()->status);
        $this->assertFalse(User::query()->where('email', 'late@example.com')->exists());
    }

    public function test_a_revoked_invitation_is_refused(): void
    {
        $this->withCsrfToken()->actingAs($this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID));
        $token = $this->tokenFromUrl((string) $this->invite('gone@example.com')->json('meta.invitation_url'));
        $this->app['auth']->guard()->logout();
        UserInvitation::query()->update(['status' => UserInvitation::STATUS_REVOKED]);

        $this->completeLogin($token, 'gone@example.com')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invitation_revoked');

        $this->assertFalse(User::query()->where('email', 'gone@example.com')->exists());
    }

    public function test_a_user_invitation_never_changes_an_administrators_role(): void
    {
        $admin = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        UserInvitation::query()->create([
            'email' => $admin->email,
            'token_hash' => hash('sha256', 'admin-token'),
            'status' => UserInvitation::STATUS_PENDING,
            'invited_by_user_id' => $admin->getKey(),
            'expires_at' => now()->addDay(),
        ]);

        $this->completeLogin('admin-token', $admin->email)->assertUnprocessable();

        $this->assertSame(User::PANE_ADMINISTRATOR_USER_TYPE_ID, (int) $admin->fresh()->user_type_id);
    }

    public function test_open_signup_creates_a_standard_user_only_when_on(): void
    {
        config()->set('pane.signup.open', false);

        $this->completeLogin(null, 'walkin@example.com')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'membership_required');
        $this->assertFalse(User::query()->where('email', 'walkin@example.com')->exists());

        config()->set('pane.signup.open', true);

        $this->completeLogin(null, 'walkin@example.com')
            ->assertOk()
            ->assertJsonPath('data.user.attributes.role', 'user');

        $user = User::query()->where('email', 'walkin@example.com')->firstOrFail();
        $this->assertSame(User::STANDARD_USER_TYPE_ID, (int) $user->user_type_id);
        $this->assertTrue((bool) $user->is_active);
    }

    public function test_open_signup_needs_a_verified_email_and_never_makes_administrators(): void
    {
        config()->set('pane.signup.open', true);

        $this->completeLogin(null, 'unverified@example.com', verified: false)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'membership_required');

        $this->assertFalse(User::query()->where('email', 'unverified@example.com')->exists());
        $this->assertSame(0, User::query()->where('user_type_id', User::PANE_ADMINISTRATOR_USER_TYPE_ID)->count());
    }

    public function test_inviting_a_user_emails_the_link_in_plain_text(): void
    {
        Mail::fake();
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($actor);

        $create = $this->invite('mailed.user@example.com')
            ->assertCreated()
            ->assertJsonPath('meta.email_sent', true);
        $url = (string) $create->json('meta.invitation_url');

        Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($url, $actor): bool {
            $mail->assertSeeInText($url);
            $mail->assertSeeInText($actor->name);
            $mail->assertDontSeeInHtml('<p>');

            return $mail->hasTo('mailed.user@example.com')
                && $mail->invitationUrl === $url
                && $mail->role === 'user'
                && $mail->inviterName === $actor->name;
        });
    }

    public function test_a_mail_failure_keeps_the_user_invitation(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));
        Log::shouldReceive('warning')->once();
        $this->withCsrfToken()->actingAs($this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID));

        $this->invite('unsent.user@example.com')
            ->assertCreated()
            ->assertJsonPath('meta.email_sent', false)
            ->assertJsonPath('data.attributes.status', UserInvitation::STATUS_PENDING);

        $this->assertSame(1, UserInvitation::query()->count());
    }

    public function test_resending_emails_a_fresh_link_without_a_new_row(): void
    {
        Mail::fake();
        $this->withCsrfToken()->actingAs($this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID));
        $create = $this->invite('again.user@example.com')->assertCreated();
        $id = (string) $create->json('data.id');
        $oldToken = $this->tokenFromUrl((string) $create->json('meta.invitation_url'));

        $resend = $this->resend($id)
            ->assertOk()
            ->assertJsonPath('meta.email_sent', true)
            ->assertJsonPath('data.id', $id);

        $newToken = $this->tokenFromUrl((string) $resend->json('meta.invitation_url'));
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame(1, UserInvitation::query()->count());
        $this->assertSame(hash('sha256', $newToken), UserInvitation::query()->firstOrFail()->token_hash);
        Mail::assertSent(InvitationMail::class, 2);
    }

    public function test_only_a_pending_invitation_can_be_resent_and_only_by_an_administrator(): void
    {
        Mail::fake();
        $this->withCsrfToken()->actingAs($this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID));
        $id = (string) $this->invite('revoked.user@example.com')->json('data.id');
        UserInvitation::query()->whereKey($id)->update(['status' => UserInvitation::STATUS_REVOKED]);

        $this->resend($id)->assertStatus(409);

        $this->actingAs($this->makePaneUser(User::STANDARD_USER_TYPE_ID));
        $this->resend($id)->assertForbidden();

        Mail::assertSentCount(1);
    }

    private function resend(string $id): TestResponse
    {
        return $this->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/installation/user-invitations/'.$id.'/resend');
    }

    private function invite(string $email): TestResponse
    {
        return $this->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/installation/user-invitations', ['email' => $email]);
    }

    private function tokenFromUrl(string $url): string
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['invitation_token'];
    }

    private function completeLogin(?string $token, string $email, bool $verified = true): TestResponse
    {
        Http::fake([
            'api.workos.com/user_management/authenticate' => Http::response([
                'user' => [
                    'id' => 'user_'.md5($email),
                    'email' => $email,
                    'email_verified' => $verified,
                    'first_name' => 'Test',
                    'last_name' => 'Person',
                ],
                'session_id' => 'session_123',
                'organization_id' => 'org_123',
                'authentication_method' => 'sso',
            ]),
        ]);

        $session = array_merge($this->v1ApplicationSession(), [
            'workos_state' => 'expected_state',
            'workos_intended_url' => 'https://latte.test/dashboard',
        ]);

        if ($token !== null) {
            $session['pane_admin_invitation_token_hash'] = hash('sha256', $token);
        }

        return $this->withSession($session)
            ->withHeader('Origin', 'https://latte.test')
            ->postJson('/api/v1/auth/callback', ['code' => 'code_123', 'state' => 'expected_state']);
    }
}
