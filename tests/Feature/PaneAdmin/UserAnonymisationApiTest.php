<?php

namespace Tests\Feature\PaneAdmin;

use App\Models\AuditEvent;
use App\Models\PaneAdminInvitation;
use App\Models\User;
use App\Services\AuditEventService;
use App\Services\PaneAdminLifecycleService;
use App\Services\UserAnonymisationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UserAnonymisationApiTest extends TestCase
{
    private const string MEMBER_EMAIL = 'erase.me@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        PaneAdminInvitation::query()->delete();
        User::query()->delete();

        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://latte.test');
    }

    public function test_user_can_anonymise_themselves(): void
    {
        Http::fake(['api.workos.com/user_management/users/*' => Http::response(null, 204)]);
        $member = $this->makeMember();
        $this->withCsrfToken()->actingAs($member);

        $this->deleteUser($member)->assertNoContent()->assertHeader('X-Request-Id');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.workos.com/user_management/users/user_workos_member'
            && $request->hasHeader('Authorization', 'Bearer sk_test_123'));

        $this->assertAnonymised($member->fresh());
        $this->assertGuest();
    }

    public function test_member_cannot_sign_in_again_after_anonymisation(): void
    {
        Http::fake(['api.workos.com/user_management/users/*' => Http::response(null, 204)]);
        $member = $this->makeMember();
        $this->withCsrfToken()->actingAs($member);
        $this->deleteUser($member)->assertNoContent();

        $anonymised = $member->fresh();

        $this->assertFalse($anonymised->is_active);
        $this->assertFalse($anonymised->isPaneAdministrator());
        $this->assertNull($anonymised->workos_id);
        $this->assertNull(User::query()->where('email', self::MEMBER_EMAIL)->first());
    }

    public function test_admin_can_anonymise_a_member_and_email_leaves_invitations_and_audit(): void
    {
        Http::fake(['api.workos.com/user_management/users/*' => Http::response(null, 204)]);
        $admin = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $member = $this->makeMember();

        $invitation = PaneAdminInvitation::query()->create([
            'email' => self::MEMBER_EMAIL,
            'token_hash' => hash('sha256', 'token'),
            'status' => PaneAdminInvitation::STATUS_ACCEPTED,
            'invited_by_user_id' => $admin->getKey(),
            'accepted_by_user_id' => $member->getKey(),
            'expires_at' => now()->addDay(),
            'accepted_at' => now(),
        ]);
        $pending = app(PaneAdminLifecycleService::class)->invitePaneAdministrator($admin, self::MEMBER_EMAIL)['invitation'];
        $untouched = app(PaneAdminLifecycleService::class)->invitePaneAdministrator($admin, 'someone.else@example.com')['invitation'];

        $this->withCsrfToken()->actingAs($admin);
        $this->deleteUser($member)->assertNoContent();

        $this->assertAnonymised($member->fresh());
        $this->assertStringNotContainsString(self::MEMBER_EMAIL, (string) $invitation->fresh()->email);
        $this->assertSame(PaneAdminInvitation::STATUS_REVOKED, $pending->fresh()->status);
        $this->assertStringNotContainsString(self::MEMBER_EMAIL, (string) $pending->fresh()->email);
        $this->assertSame('someone.else@example.com', $untouched->fresh()->email);

        $this->assertNoEmailInAudit(self::MEMBER_EMAIL);
        $this->assertTrue(AuditEvent::query()->where('action', 'user.anonymise')
            ->where('real_actor_user_id', $admin->getKey())->exists());
        $this->assertSame([
            'installation.admin.invite',
        ], AuditEvent::query()->where('action', 'installation.admin.invite')->limit(1)->pluck('action')->all());
    }

    public function test_another_user_cannot_anonymise_a_member(): void
    {
        Http::fake();
        $member = $this->makeMember();
        $other = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($other);

        $this->deleteUser($member)->assertForbidden()->assertJsonPath('error.code', 'permission_denied');

        Http::assertNothingSent();
        $this->assertSame(self::MEMBER_EMAIL, $member->fresh()->email);
    }

    public function test_unknown_user_is_not_found_for_admin_and_denied_for_member(): void
    {
        $admin = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $missing = '8d1d8a0e-6a6c-4d6b-9d52-4f3f6c6b8a11';

        $this->withCsrfToken()->actingAs($admin);
        $this->deleteJsonUuid($missing)->assertNotFound();
        $this->deleteJsonUuid('not-a-uuid')->assertStatus(400)->assertJsonPath('error.code', 'invalid_identifier');

        $this->withCsrfToken()->actingAs($this->makePaneUser(User::STANDARD_USER_TYPE_ID));
        $this->deleteJsonUuid($missing)->assertForbidden();
    }

    public function test_workos_failure_changes_nothing_and_retry_succeeds(): void
    {
        $member = $this->makeMember();
        $this->withCsrfToken()->actingAs($member);

        Http::fake(['api.workos.com/user_management/users/*' => Http::sequence()
            ->push(['message' => 'boom user_workos_member'], 500)
            ->push(['message' => 'not found'], 404)]);
        $failed = $this->deleteUser($member);

        $failed->assertStatus(503)->assertJsonPath('error.code', 'dependency_unavailable');
        $this->assertStringNotContainsString('user_workos_member', $failed->getContent());
        $this->assertSame(self::MEMBER_EMAIL, $member->fresh()->email);
        $this->assertSame('user_workos_member', $member->fresh()->workos_id);
        $this->assertTrue($member->fresh()->is_active);

        $this->withCsrfToken()->actingAs($member->fresh());
        $this->deleteUser($member)->assertNoContent();

        $this->assertAnonymised($member->fresh());
    }

    public function test_anonymising_again_is_idempotent_for_an_admin(): void
    {
        Http::fake(['api.workos.com/user_management/users/*' => Http::response(null, 204)]);
        $admin = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $member = $this->makeMember();
        $this->withCsrfToken()->actingAs($admin);

        $this->deleteUser($member)->assertNoContent();
        $emailAfterFirstCall = $member->fresh()->email;
        $this->deleteUser($member)->assertNoContent();

        $this->assertSame($emailAfterFirstCall, $member->fresh()->email);
        Http::assertSentCount(1);
        $this->assertSame(1, AuditEvent::query()->where('action', 'user.anonymise')
            ->where('effective_user_id', $member->getKey())->count());
    }

    public function test_final_active_administrator_cannot_be_anonymised(): void
    {
        Http::fake();
        $admin = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($admin);

        $this->deleteUser($admin)->assertStatus(409)->assertJsonPath('error.code', 'operation_conflict');

        Http::assertNothingSent();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_reinviting_the_same_email_creates_a_new_user_with_no_link_to_the_old_one(): void
    {
        Http::fake(['api.workos.com/user_management/users/*' => Http::response(null, 204)]);
        $admin = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $member = $this->makeMember();
        app(UserAnonymisationService::class)->anonymise($admin, $member);

        $lifecycle = app(PaneAdminLifecycleService::class);
        $result = $lifecycle->invitePaneAdministrator($admin, self::MEMBER_EMAIL);
        $newUser = $lifecycle->acceptPaneAdministratorInvitation($result['token'], [
            'id' => 'user_workos_new',
            'email' => self::MEMBER_EMAIL,
            'email_verified' => true,
        ]);

        $this->assertFalse($newUser->is($member));
        $this->assertNotSame($member->uuid, $newUser->uuid);
        $this->assertSame(self::MEMBER_EMAIL, $newUser->email);
        $this->assertAnonymised($member->fresh());
    }

    public function test_audit_events_stay_append_only_apart_from_metadata(): void
    {
        $event = app(AuditEventService::class)->record('settings.update', AuditEvent::OUTCOME_DENIED, [
            'metadata' => ['email' => 'x@example.com'],
        ]);

        DB::table($event->getTable())->where('audit_event_id', $event->getKey())
            ->update(['metadata' => json_encode(['email' => '[redacted]'])]);
        $this->assertSame('[redacted]', $event->fresh()->metadata['email']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Audit events are append-only.');
        DB::table($event->getTable())->where('audit_event_id', $event->getKey())
            ->update(['outcome' => AuditEvent::OUTCOME_SUCCESS]);
    }

    private function makeMember(): User
    {
        return User::query()->create([
            'user_type_id' => User::STANDARD_USER_TYPE_ID,
            'name' => 'Erase Me',
            'email' => self::MEMBER_EMAIL,
            'password' => 'password',
            'workos_id' => 'user_workos_member',
            'workos_organization_id' => 'org_123',
            'details' => ['workos' => ['first_name' => 'Erase']],
            'is_active' => true,
        ]);
    }

    private function deleteUser(User $user): TestResponse
    {
        return $this->deleteJsonUuid($user->uuid);
    }

    private function deleteJsonUuid(string $uuid): TestResponse
    {
        return $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->deleteJson("/api/v1/users/$uuid");
    }

    private function assertAnonymised(User $user): void
    {
        $this->assertStringStartsWith('anon-', $user->name);
        $this->assertStringStartsWith('anon-', $user->email);
        $this->assertNull($user->workos_id);
        $this->assertNull($user->workos_organization_id);
        $this->assertFalse($user->is_active);
        $this->assertSame(['anonymised_at'], array_keys($user->details));
        $this->assertTrue(app(UserAnonymisationService::class)->isAnonymised($user));
    }

    private function assertNoEmailInAudit(string $email): void
    {
        foreach (AuditEvent::query()->get() as $event) {
            $this->assertStringNotContainsStringIgnoringCase(
                $email,
                json_encode($event->getAttributes(), JSON_THROW_ON_ERROR),
            );
        }
    }
}
