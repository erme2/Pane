<?php

namespace Tests\Feature\Organizations;

use App\Models\ApplicationRegistration;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OrganizationTenancyService;
use DomainException;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationLifecycleApiTest extends TestCase
{
    private OrganizationTenancyService $tenancy;

    protected function setUp(): void
    {
        parent::setUp();

        ApplicationRegistration::query()->delete();
        OrganizationMembership::query()->delete();
        Organization::query()->delete();
        User::query()->delete();

        $this->tenancy = app(OrganizationTenancyService::class);
    }

    public function test_pane_admin_can_create_list_inspect_suspend_close_reopen_and_lower_quota(): void
    {
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($actor);

        $created = $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/installation/organizations', [
                'name' => 'Quota Workspace',
                'slug' => 'quota-workspace-'.Str::uuid(),
                'database_limit' => 2,
            ]);

        $created
            ->assertCreated()
            ->assertHeader('ETag')
            ->assertJsonPath('data.type', 'organization')
            ->assertJsonPath('data.attributes.status', Organization::STATUS_ACTIVE)
            ->assertJsonPath('data.attributes.database_limit', 2)
            ->assertJsonPath('data.attributes.active_database_connections', 0)
            ->assertJsonPath('data.attributes.over_database_limit', false);

        $organizationId = (string) $created->json('data.id');

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson('/api/v1/installation/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $organizationId);

        $this->tenancy->reserveDatabaseConnectionSlot(Organization::query()->findOrFail($organizationId));
        $this->tenancy->reserveDatabaseConnectionSlot(Organization::query()->findOrFail($organizationId));

        $reserved = $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson("/api/v1/installation/organizations/$organizationId")
            ->assertOk()
            ->assertJsonPath('data.attributes.active_database_connections', 2);

        $suspended = $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', (string) $reserved->headers->get('ETag'))
            ->patchJson("/api/v1/installation/organizations/$organizationId", [
                'status' => Organization::STATUS_SUSPENDED,
                'database_limit' => 1,
            ]);

        $suspended
            ->assertOk()
            ->assertHeader('ETag')
            ->assertJsonPath('data.attributes.status', Organization::STATUS_SUSPENDED)
            ->assertJsonPath('data.attributes.database_limit', 1)
            ->assertJsonPath('data.attributes.active_database_connections', 2)
            ->assertJsonPath('data.attributes.over_database_limit', true);

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', (string) $suspended->headers->get('ETag'))
            ->patchJson("/api/v1/installation/organizations/$organizationId", [
                'status' => Organization::STATUS_CLOSED,
            ])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', Organization::STATUS_CLOSED);

        $closed = Organization::query()->findOrFail($organizationId);

        $this->assertSame(2, $closed->active_database_connections);

        $reloaded = $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson("/api/v1/installation/organizations/$organizationId")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', Organization::STATUS_CLOSED)
            ->assertJsonPath('data.attributes.over_database_limit', true);

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', (string) $reloaded->headers->get('ETag'))
            ->patchJson("/api/v1/installation/organizations/$organizationId", [
                'status' => Organization::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', Organization::STATUS_ACTIVE)
            ->assertJsonPath('data.attributes.over_database_limit', true);

        $this->assertTrue(AuditEvent::query()
            ->where('real_actor_user_id', $actor->getKey())
            ->where('organization_id', $organizationId)
            ->where('action', 'organization.update')
            ->where('outcome', AuditEvent::OUTCOME_SUCCESS)
            ->exists());
    }

    public function test_organization_lifecycle_rejects_non_pane_admins_stale_versions_and_duplicate_slugs(): void
    {
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $member = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $organization = $this->tenancy->createOrganization('Original Workspace', 'original-workspace-'.Str::uuid(), 1);
        $otherOrganization = $this->tenancy->createOrganization('Other Workspace', 'other-workspace-'.Str::uuid(), 1);

        $this->withCsrfToken()->actingAs($member);

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/installation/organizations', [
                'name' => 'Rejected Workspace',
                'slug' => 'rejected-workspace',
                'database_limit' => 1,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'permission_denied');

        $this->actingAs($actor);

        $loaded = $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson('/api/v1/installation/organizations/'.$organization->organization_id)
            ->assertOk();

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->patchJson('/api/v1/installation/organizations/'.$organization->organization_id, [
                'status' => Organization::STATUS_SUSPENDED,
            ])
            ->assertStatus(Response::HTTP_PRECONDITION_REQUIRED)
            ->assertJsonPath('error.code', 'precondition_required');

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', '"stale"')
            ->patchJson('/api/v1/installation/organizations/'.$organization->organization_id, [
                'status' => Organization::STATUS_SUSPENDED,
            ])
            ->assertStatus(Response::HTTP_PRECONDITION_FAILED)
            ->assertJsonPath('error.code', 'version_conflict');

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', (string) $loaded->headers->get('ETag'))
            ->patchJson('/api/v1/installation/organizations/'.$organization->organization_id, [
                'slug' => $otherOrganization->slug,
            ])
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('error.code', 'duplicate_resource');
    }

    public function test_organization_update_rechecks_expected_version_after_locking_the_row(): void
    {
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $organization = $this->tenancy->createOrganization('Concurrent Workspace', 'concurrent-workspace-'.Str::uuid(), 1);
        $staleVersion = $organization->versionTag();

        $this->tenancy->updateOrganization($actor, $organization, [
            'name' => 'Updated Concurrent Workspace',
        ], $staleVersion);

        try {
            $this->tenancy->updateOrganization($actor, $organization, [
                'status' => Organization::STATUS_SUSPENDED,
            ], $staleVersion);
            $this->fail('Expected stale organization update to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(OrganizationTenancyService::VERSION_CONFLICT_MESSAGE, $exception->getMessage());
            $this->assertSame(Organization::STATUS_ACTIVE, $organization->fresh()->status);
        }
    }

    public function test_inactive_organizations_block_existing_sessions_and_connection_slot_reservation(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $organization = $this->tenancy->createOrganization('Inactive Workspace', 'inactive-workspace-'.Str::uuid(), 1);
        $this->tenancy->addOrReactivateMembership(
            $organization,
            $user,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        $origin = 'https://inactive-'.Str::uuid().'.example.test';
        $application = ApplicationRegistration::query()->create([
            'name' => 'Inactive App',
            'kind' => 'latte',
            'organization_id' => $organization->organization_id,
            'trusted_origin' => $origin,
            'redirect_uris' => [$origin.'/auth/callback'],
            'status' => 'active',
        ]);

        $organization->forceFill(['status' => Organization::STATUS_SUSPENDED])->save();
        $this->actingAs($user);

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', $origin)
            ->getJson('/api/v1/organizations/'.$organization->organization_id.'/memberships')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'organization_inactive');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The organization is inactive.');

        $this->tenancy->reserveDatabaseConnectionSlot($organization);
    }

    public function test_database_quota_slot_accounting_is_transactional_and_blocks_until_compliant(): void
    {
        $organization = $this->tenancy->createOrganization('Quota Accounting', 'quota-accounting-'.Str::uuid(), 2);

        $this->tenancy->reserveDatabaseConnectionSlot($organization);
        $full = $this->tenancy->reserveDatabaseConnectionSlot($organization);

        $this->assertSame(2, $full->active_database_connections);

        try {
            $this->tenancy->reserveDatabaseConnectionSlot($organization);
            $this->fail('Expected quota reservation to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('The organization database connection quota is exceeded.', $exception->getMessage());
            $this->assertSame(2, $organization->fresh()->active_database_connections);
        }

        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $overQuota = $this->tenancy->updateOrganization($actor, $organization, ['database_limit' => 1]);

        $this->assertTrue($overQuota->isOverDatabaseLimit());
        $this->assertSame(2, $overQuota->active_database_connections);

        try {
            $this->tenancy->reserveDatabaseConnectionSlot($organization);
            $this->fail('Expected over-quota reservation to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('The organization database connection quota is exceeded.', $exception->getMessage());
            $this->assertSame(2, $organization->fresh()->active_database_connections);
        }

        $this->tenancy->releaseDatabaseConnectionSlot($organization);
        $compliant = $this->tenancy->releaseDatabaseConnectionSlot($organization);

        $this->assertSame(0, $compliant->active_database_connections);
        $this->assertFalse($compliant->isOverDatabaseLimit());

        $reserved = $this->tenancy->reserveDatabaseConnectionSlot($organization);

        $this->assertSame(1, $reserved->active_database_connections);
        $this->assertFalse($reserved->isOverDatabaseLimit());
    }
}
