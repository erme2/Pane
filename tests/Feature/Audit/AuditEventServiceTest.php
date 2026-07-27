<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\AuditEventService;
use App\Services\OrganizationTenancyService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditEventServiceTest extends TestCase
{
    private AuditEventService $audit;

    private OrganizationTenancyService $tenancy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit = app(AuditEventService::class);
        $this->tenancy = app(OrganizationTenancyService::class);
    }

    public function test_records_actor_context_resource_metadata_and_redacts_sensitive_values(): void
    {
        $realActor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $effectiveUser = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $organization = $this->createOrganization('Audited Workspace');

        $event = $this->audit->record('organization.membership.invite', AuditEvent::OUTCOME_SUCCESS, [
            'real_actor' => $realActor,
            'effective_user' => $effectiveUser,
            'organization' => $organization,
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
        $this->assertSame($organization->getKey(), $event->organization_id);
        $this->assertSame('organization.membership.invite', $event->action);
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

    public function test_pane_admin_can_view_installation_events_and_org_admin_only_sees_own_organization(): void
    {
        $paneAdministrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $organizationAdministrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $organizationUser = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $externalAdministrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);

        $organization = $this->createOrganization('Audit Workspace');
        $otherOrganization = $this->createOrganization('Other Audit Workspace');

        $this->tenancy->addOrReactivateMembership(
            $organization,
            $organizationAdministrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );
        $this->tenancy->addOrReactivateMembership(
            $organization,
            $organizationUser,
            OrganizationMembership::ROLE_USER
        );
        $this->tenancy->addOrReactivateMembership(
            $otherOrganization,
            $externalAdministrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        $organizationEvent = $this->audit->record('organization.member.suspend', AuditEvent::OUTCOME_SUCCESS, [
            'organization' => $organization,
        ]);
        $otherOrganizationEvent = $this->audit->record('organization.member.suspend', AuditEvent::OUTCOME_SUCCESS, [
            'organization' => $otherOrganization,
        ]);
        $installationEvent = $this->audit->record('installation.admin.invite', AuditEvent::OUTCOME_DENIED);

        $installationEvents = $this->audit->installationEventsFor($paneAdministrator);

        $this->assertTrue($installationEvents->contains($organizationEvent));
        $this->assertTrue($installationEvents->contains($otherOrganizationEvent));
        $this->assertTrue($installationEvents->contains($installationEvent));
        $this->assertTrue(Gate::forUser($paneAdministrator)->allows('viewAny', AuditEvent::class));

        $organizationEvents = $this->audit->organizationEventsFor($organizationAdministrator, $organization);

        $this->assertTrue($organizationEvents->contains($organizationEvent));
        $this->assertFalse($organizationEvents->contains($otherOrganizationEvent));
        $this->assertFalse($organizationEvents->contains($installationEvent));
        $this->assertTrue(Gate::forUser($organizationAdministrator)->allows('view', $organizationEvent));
        $this->assertTrue(Gate::forUser($organizationAdministrator)->denies('view', $otherOrganizationEvent));
        $this->assertTrue(Gate::forUser($organizationAdministrator)->denies('view', $installationEvent));

        $this->expectException(DomainException::class);
        $this->audit->organizationEventsFor($organizationUser, $organization);
    }

    public function test_non_pane_admin_cannot_view_installation_events(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);

        $this->expectException(DomainException::class);
        $this->audit->installationEventsFor($user);
    }

    private function createOrganization(string $name): Organization
    {
        return $this->tenancy->createOrganization($name, $name.' '.Str::uuid());
    }
}
