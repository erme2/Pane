<?php

namespace Tests\Feature\PaneAdmin;

use App\Models\AuditEvent;
use App\Models\PaneAdminInvitation;
use App\Models\User;
use App\Services\PaneAdminLifecycleService;
use App\Support\PaneTable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class PaneAdminLifecycleServiceTest extends TestCase
{
    private PaneAdminLifecycleService $administrators;

    protected function setUp(): void
    {
        parent::setUp();

        PaneAdminInvitation::query()->delete();
        User::query()->delete();

        $this->administrators = app(PaneAdminLifecycleService::class);
    }

    public function test_bootstrap_command_creates_exactly_first_pane_administrator_idempotently(): void
    {
        $this->artisan('pane:bootstrap-admin', [
            'email' => 'First.Admin@Example.COM',
            '--name' => 'First Admin',
        ])->assertExitCode(0);

        $administrator = User::query()->where('email', 'first.admin@example.com')->firstOrFail();

        $this->assertDatabaseHas(PaneTable::name(PaneTable::PANE_INSTALLATION_LOCKS), [
            'lock_name' => 'pane_admin_bootstrap',
        ]);
        $this->assertSame(User::PANE_ADMINISTRATOR_USER_TYPE_ID, $administrator->user_type_id);
        $this->assertSame('First Admin', $administrator->name);
        $this->assertTrue((bool) $administrator->is_active);
        $this->assertNotNull($administrator->email_verified_at);

        $this->artisan('pane:bootstrap-admin', [
            'email' => 'first.admin@example.com',
        ])->assertExitCode(0);

        $this->assertSame(1, User::query()->where('user_type_id', User::PANE_ADMINISTRATOR_USER_TYPE_ID)->count());

        $this->artisan('pane:bootstrap-admin', [
            'email' => 'second.admin@example.com',
        ])->assertExitCode(1);

        $this->assertSame(1, User::query()->where('user_type_id', User::PANE_ADMINISTRATOR_USER_TYPE_ID)->count());
        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'installation.admin.bootstrap')
                ->where('outcome', AuditEvent::OUTCOME_SUCCESS)
                ->exists()
        );
    }

    public function test_pane_admin_invitation_acceptance_is_email_bound_single_use_and_not_logged(): void
    {
        $actor = $this->administrators->bootstrapFirstAdministrator('root@example.com', 'Root Admin');
        $result = $this->administrators->invitePaneAdministrator($actor, 'Invited.Admin@Example.COM');

        /** @var PaneAdminInvitation $invitation */
        $invitation = $result['invitation'];
        $token = $result['token'];

        $this->assertSame('invited.admin@example.com', $invitation->email);
        $this->assertSame(PaneAdminInvitation::STATUS_PENDING, $invitation->status);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertFalse(
            DB::table(PaneTable::name(PaneTable::PANE_ADMIN_INVITATIONS))
                ->where('token_hash', $token)
                ->exists()
        );

        try {
            $this->administrators->acceptPaneAdministratorInvitation($token, [
                'id' => 'user_wrong',
                'email' => 'wrong@example.com',
                'email_verified' => true,
            ]);
            $this->fail('Expected email-mismatched invitation acceptance to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Pane administrator invitation email does not match the WorkOS identity.',
                $exception->getMessage()
            );
        }

        $accepted = $this->administrators->acceptPaneAdministratorInvitation(
            $token,
            [
                'id' => 'user_invited',
                'email' => 'invited.admin@example.com',
                'email_verified' => true,
                'first_name' => 'Invited',
                'last_name' => 'Admin',
            ],
            [
                'organization_id' => 'org_123',
                'authentication_method' => 'sso',
            ]
        );

        $this->assertSame(User::PANE_ADMINISTRATOR_USER_TYPE_ID, $accepted->user_type_id);
        $this->assertSame('Invited Admin', $accepted->name);
        $this->assertSame('user_invited', $accepted->workos_id);
        $this->assertTrue((bool) $accepted->is_active);
        $this->assertSame(PaneAdminInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);

        try {
            $this->administrators->acceptPaneAdministratorInvitation($token, [
                'id' => 'user_invited',
                'email' => 'invited.admin@example.com',
                'email_verified' => true,
            ]);
            $this->fail('Expected single-use invitation acceptance to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Pane administrator invitation was already accepted.', $exception->getMessage());
        }

        $this->assertStringNotContainsString($token, AuditEvent::query()->get()->toJson());
    }

    public function test_resend_revocation_and_expiry_invalidate_old_tokens(): void
    {
        $actor = $this->administrators->bootstrapFirstAdministrator('root@example.com', 'Root Admin');
        $first = $this->administrators->invitePaneAdministrator($actor, 'second@example.com');
        $second = $this->administrators->resendPaneAdministratorInvitation($actor, $first['invitation']);

        $this->assertSame(PaneAdminInvitation::STATUS_REVOKED, $first['invitation']->fresh()->status);

        try {
            $this->administrators->acceptPaneAdministratorInvitation($first['token'], [
                'id' => 'user_second',
                'email' => 'second@example.com',
                'email_verified' => true,
            ]);
            $this->fail('Expected replaced invitation token to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Pane administrator invitation was revoked.', $exception->getMessage());
        }

        $accepted = $this->administrators->acceptPaneAdministratorInvitation($second['token'], [
            'id' => 'user_second',
            'email' => 'second@example.com',
            'email_verified' => true,
        ]);

        $this->assertTrue($accepted->isPaneAdministrator());

        $expired = $this->administrators->invitePaneAdministrator($actor, 'expired@example.com');
        $expired['invitation']->forceFill(['expires_at' => now()->subSecond()])->save();

        try {
            $this->administrators->acceptPaneAdministratorInvitation($expired['token'], [
                'id' => 'user_expired',
                'email' => 'expired@example.com',
                'email_verified' => true,
            ]);
            $this->fail('Expected expired invitation token to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Pane administrator invitation has expired.', $exception->getMessage());
        }

        $this->assertSame(PaneAdminInvitation::STATUS_EXPIRED, $expired['invitation']->fresh()->status);
    }

    public function test_pane_admin_suspension_enforces_final_active_administrator_invariant(): void
    {
        $actor = $this->administrators->bootstrapFirstAdministrator('root@example.com', 'Root Admin');
        $second = $this->administrators->acceptPaneAdministratorInvitation(
            $this->administrators->invitePaneAdministrator($actor, 'second@example.com')['token'],
            [
                'id' => 'user_second',
                'email' => 'second@example.com',
                'email_verified' => true,
            ]
        );

        $suspended = $this->administrators->suspendPaneAdministrator($actor, $second);

        $this->assertFalse((bool) $suspended->is_active);

        try {
            $this->administrators->suspendPaneAdministrator($actor, $actor);
            $this->fail('Expected final active Pane administrator suspension to fail.');
        } catch (DomainException $exception) {
            $this->assertSame('Cannot suspend the final active Pane administrator.', $exception->getMessage());
        }

        $reactivated = $this->administrators->reactivatePaneAdministrator($actor, $second);

        $this->assertTrue((bool) $reactivated->is_active);
        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'installation.admin.suspend')
                ->where('outcome', AuditEvent::OUTCOME_DENIED)
                ->exists()
        );
    }
}
