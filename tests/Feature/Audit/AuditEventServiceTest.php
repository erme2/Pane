<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditEventService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class AuditEventServiceTest extends TestCase
{
    private AuditEventService $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit = app(AuditEventService::class);
    }

    public function test_records_actor_context_resource_metadata_and_redacts_sensitive_values(): void
    {
        $realActor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $effectiveUser = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);

        $event = $this->audit->record('installation.admin.invite', AuditEvent::OUTCOME_SUCCESS, [
            'real_actor' => $realActor,
            'effective_user' => $effectiveUser,
            'resource_ids' => [
                'membership_id' => (string) Str::uuid(),
                'invitation_token' => 'raw-token',
            ],
            'request_id' => (string) Str::uuid(),
            'client_metadata' => [
                'ip' => '127.0.0.1',
                'user_agent' => 'Feature test',
                'authorization_token' => 'Bearer secret',
            ],
            'impersonation_session_id' => (string) Str::uuid(),
            'connection_id' => (string) Str::uuid(),
            'table_name' => 'contacts',
            'row_key' => 'contact-1',
            'changed_columns' => ['name', 'name', 'email'],
            'metadata' => [
                'safe_note' => 'invitation queued',
                'password' => 'plain',
                'sql' => 'select * from contacts',
                'row_values' => ['name' => 'Ada'],
                'nested' => [
                    'certificate_pem' => '-----BEGIN CERTIFICATE-----',
                    'safe' => 'visible',
                ],
            ],
        ]);

        $this->assertNotEmpty($event->audit_event_id);
        $this->assertSame($realActor->getKey(), $event->real_actor_user_id);
        $this->assertSame($effectiveUser->getKey(), $event->effective_user_id);
        $this->assertSame('installation.admin.invite', $event->action);
        $this->assertSame(AuditEvent::OUTCOME_SUCCESS, $event->outcome);
        $this->assertSame(['name', 'email'], $event->changed_columns);
        $this->assertSame('invitation queued', $event->metadata['safe_note']);
        $this->assertSame('[redacted]', $event->metadata['password']);
        $this->assertSame('[redacted]', $event->metadata['sql']);
        $this->assertSame('[redacted]', $event->metadata['row_values']);
        $this->assertSame('[redacted]', $event->metadata['nested']['certificate_pem']);
        $this->assertSame('visible', $event->metadata['nested']['safe']);
        $this->assertSame('[redacted]', $event->resource_ids['invitation_token']);
        $this->assertSame('[redacted]', $event->client_metadata['authorization_token']);
    }

    public function test_audit_events_are_append_only(): void
    {
        $event = $this->audit->record('settings.update', AuditEvent::OUTCOME_DENIED);

        try {
            $event->forceFill(['outcome' => AuditEvent::OUTCOME_SUCCESS])->save();
            $this->fail('Expected audit event updates to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('Audit events are append-only.', $exception->getMessage());
        }

        $this->assertSame(AuditEvent::OUTCOME_DENIED, $event->fresh()->outcome);

        try {
            $event->delete();
            $this->fail('Expected audit event deletion to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('Audit events are append-only.', $exception->getMessage());
        }

        $this->assertTrue(AuditEvent::query()->whereKey($event->getKey())->exists());

        try {
            AuditEvent::query()
                ->whereKey($event->getKey())
                ->update(['outcome' => AuditEvent::OUTCOME_SUCCESS]);

            $this->fail('Expected audit event bulk updates to be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Audit events are append-only.', $exception->getMessage());
        }

        $this->assertSame(AuditEvent::OUTCOME_DENIED, $event->fresh()->outcome);

        try {
            DB::table($event->getTable())
                ->where('audit_event_id', $event->getKey())
                ->delete();

            $this->fail('Expected audit event table deletes to be rejected.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Audit events are append-only.', $exception->getMessage());
        }

        $this->assertTrue(AuditEvent::query()->whereKey($event->getKey())->exists());
    }

    public function test_audit_event_records_participate_in_caller_transactions(): void
    {
        $action = 'transaction.rollback.'.Str::uuid();
        $shouldRollback = (bool) config('app.debug');

        try {
            DB::transaction(function () use ($action, $shouldRollback): void {
                $this->audit->record($action, AuditEvent::OUTCOME_FAILURE);

                if ($shouldRollback) {
                    throw new DomainException('rollback');
                }
            });

            $this->fail('Expected transaction to roll back.');
        } catch (DomainException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }

        $this->assertFalse(AuditEvent::query()->where('action', $action)->exists());
    }

    public function test_admin_can_view_installation_events_and_a_user_cannot(): void
    {
        $administrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);

        $event = $this->audit->record('installation.admin.invite', AuditEvent::OUTCOME_DENIED);

        $this->assertTrue($this->audit->installationEventsFor($administrator)->contains($event));
        $this->assertTrue(Gate::forUser($administrator)->allows('viewAny', AuditEvent::class));
        $this->assertTrue(Gate::forUser($administrator)->allows('view', $event));
        $this->assertTrue(Gate::forUser($user)->denies('viewAny', AuditEvent::class));
        $this->assertTrue(Gate::forUser($user)->denies('view', $event));
    }

    public function test_non_pane_admin_cannot_view_installation_events(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);

        $this->expectException(DomainException::class);
        $this->audit->installationEventsFor($user);
    }

    public function test_audit_event_views_are_bounded_and_stably_ordered(): void
    {
        $administrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);

        $baseTime = now();

        Carbon::setTestNow($baseTime->copy()->addSecond());
        $this->audit->record('audit.page.one', AuditEvent::OUTCOME_SUCCESS);

        Carbon::setTestNow($baseTime->copy()->addSeconds(2));
        $this->audit->record('audit.page.two', AuditEvent::OUTCOME_SUCCESS);

        Carbon::setTestNow($baseTime->copy()->addSeconds(3));
        $this->audit->record('audit.page.three', AuditEvent::OUTCOME_SUCCESS);

        Carbon::setTestNow();

        // Audit events are append-only, so the table also holds the events of other tests.
        $all = $this->audit->installationEventsFor($administrator, 100)->pluck('audit_event_id')->all();
        $recorded = $this->audit->installationEventsFor($administrator, 100)
            ->whereIn('action', ['audit.page.one', 'audit.page.two', 'audit.page.three'])
            ->pluck('action')
            ->take(3)
            ->all();

        $this->assertSame(['audit.page.three', 'audit.page.two', 'audit.page.one'], $recorded);
        $this->assertSame(
            array_slice($all, 0, 2),
            $this->audit->installationEventsFor($administrator, 2)->pluck('audit_event_id')->all()
        );
        $this->assertSame(
            array_slice($all, 2, 2),
            $this->audit->installationEventsFor($administrator, 2, 2)->pluck('audit_event_id')->all()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->audit->installationEventsFor($administrator, 101);
    }
}
