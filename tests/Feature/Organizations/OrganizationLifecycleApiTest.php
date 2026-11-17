<?php

namespace Tests\Feature\Organizations;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
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
            ->assertJsonPath('data.attributes.database_limit', 2);

        $organizationId = (string) $created->json('data.id');

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson('/api/v1/installation/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $organizationId);

        $reserved = $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson("/api/v1/installation/organizations/$organizationId")
            ->assertOk();

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
            ->assertJsonPath('data.attributes.database_limit', 1);

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', (string) $suspended->headers->get('ETag'))
            ->patchJson("/api/v1/installation/organizations/$organizationId", [
                'status' => Organization::STATUS_CLOSED,
            ])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', Organization::STATUS_CLOSED);

        $reloaded = $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson("/api/v1/installation/organizations/$organizationId")
            ->assertOk()
            ->assertJsonPath('data.attributes.status', Organization::STATUS_CLOSED);

        $this
            ->withV1ApplicationSession()
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', (string) $reloaded->headers->get('ETag'))
            ->patchJson("/api/v1/installation/organizations/$organizationId", [
                'status' => Organization::STATUS_ACTIVE,
            ])
            ->assertOk()
            ->assertJsonPath('data.attributes.status', Organization::STATUS_ACTIVE);

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

    public function test_inactive_organizations_block_existing_sessions(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $organization = $this->tenancy->createOrganization('Inactive Workspace', 'inactive-workspace-'.Str::uuid(), 1);
        $this->tenancy->addOrReactivateMembership(
            $organization,
            $user,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        $origin = 'https://inactive-'.Str::uuid().'.example.test';
        config()->set('services.latte.organization_id', $organization->organization_id);
        config()->set('services.latte.frontend_url', $origin);
        $application = app(ApplicationRegistryService::class)->configuredLatteApplication();

        $organization->forceFill(['status' => Organization::STATUS_SUSPENDED])->save();
        $this->actingAs($user);

        $this
            ->withV1ApplicationSession($application)
            ->withHeader('Origin', $origin)
            ->getJson('/api/v1/organizations/'.$organization->organization_id.'/memberships')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'organization_inactive');
    }
}
