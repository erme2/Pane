<?php

namespace Tests\Feature\ApplicationRegistry;

use App\Models\ApplicationRegistration;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OrganizationTenancyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationRegistryApiTest extends TestCase
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

    public function test_pane_admin_can_create_list_update_and_disable_latte_application(): void
    {
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $organization = $this->tenancy->createOrganization('Registry Workspace', 'registry-workspace-'.Str::uuid());
        $this->withCsrfToken()->actingAs($actor);

        $create = $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/installation/applications', [
                'name' => 'Customer Workspace',
                'kind' => 'latte',
                'organization_id' => $organization->organization_id,
                'trusted_origin' => 'https://Customer.EXAMPLE.test:443',
                'redirect_uris' => [
                    'https://Customer.EXAMPLE.test:443/auth/callback',
                    'https://customer.example.test/auth/callback',
                ],
            ]);

        $create
            ->assertCreated()
            ->assertHeader('ETag')
            ->assertJsonPath('data.type', 'application')
            ->assertJsonPath('data.attributes.kind', 'latte')
            ->assertJsonPath('data.attributes.organization_id', $organization->organization_id)
            ->assertJsonPath('data.attributes.trusted_origin', 'https://customer.example.test')
            ->assertJsonPath('data.attributes.redirect_uris.0', 'https://customer.example.test/auth/callback')
            ->assertJsonCount(1, 'data.attributes.redirect_uris');

        $applicationId = (string) $create->json('data.id');

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->getJson('/api/v1/installation/applications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $applicationId);

        $update = $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', (string) $create->headers->get('ETag'))
            ->patchJson("/api/v1/installation/applications/$applicationId", [
                'name' => 'Customer Workspace Renamed',
                'status' => 'disabled',
            ]);

        $update
            ->assertOk()
            ->assertHeader('ETag')
            ->assertJsonPath('data.attributes.name', 'Customer Workspace Renamed')
            ->assertJsonPath('data.attributes.status', ApplicationRegistration::STATUS_DISABLED);

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', (string) $update->headers->get('ETag'))
            ->deleteJson("/api/v1/installation/applications/$applicationId")
            ->assertNoContent();

        $this->assertSame(
            ApplicationRegistration::STATUS_DISABLED,
            ApplicationRegistration::query()->findOrFail($applicationId)->status
        );
        $this->assertNull(ApplicationRegistration::query()->findOrFail($applicationId)->active_trusted_origin);
    }

    public function test_application_registry_rejects_duplicate_active_origin_and_releases_disabled_origin(): void
    {
        $actor = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $firstOrganization = $this->tenancy->createOrganization('First Registry Workspace', 'first-registry-'.Str::uuid());
        $secondOrganization = $this->tenancy->createOrganization('Second Registry Workspace', 'second-registry-'.Str::uuid());
        $this->withCsrfToken()->actingAs($actor);

        $first = $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/installation/applications', [
                'name' => 'First Workspace App',
                'kind' => 'latte',
                'organization_id' => $firstOrganization->organization_id,
                'trusted_origin' => 'https://shared.example.test',
                'redirect_uris' => ['https://shared.example.test/auth/callback'],
            ])
            ->assertCreated();

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/installation/applications', [
                'name' => 'Second Workspace App',
                'kind' => 'latte',
                'organization_id' => $secondOrganization->organization_id,
                'trusted_origin' => 'https://shared.example.test',
                'redirect_uris' => ['https://shared.example.test/auth/callback'],
            ])
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertJsonPath('error.code', 'duplicate_resource');

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->withHeader('If-Match', (string) $first->headers->get('ETag'))
            ->deleteJson('/api/v1/installation/applications/'.$first->json('data.id'))
            ->assertNoContent();

        $this
            ->withSession(['pane_v1_application_id' => config('services.latte.application_id')])
            ->withHeader('Origin', 'https://latte.localhost')
            ->postJson('/api/v1/installation/applications', [
                'name' => 'Second Workspace App',
                'kind' => 'latte',
                'organization_id' => $secondOrganization->organization_id,
                'trusted_origin' => 'https://shared.example.test',
                'redirect_uris' => ['https://shared.example.test/auth/callback'],
            ])
            ->assertCreated();
    }

    public function test_active_origin_uniqueness_is_enforced_by_the_database(): void
    {
        $organization = $this->tenancy->createOrganization('Unique Workspace', 'unique-workspace-'.Str::uuid());

        ApplicationRegistration::query()->create([
            'name' => 'First Workspace App',
            'kind' => ApplicationRegistration::KIND_LATTE,
            'organization_id' => $organization->organization_id,
            'trusted_origin' => 'https://unique.example.test',
            'redirect_uris' => ['https://unique.example.test/auth/callback'],
            'status' => ApplicationRegistration::STATUS_ACTIVE,
        ]);

        $this->expectException(QueryException::class);

        ApplicationRegistration::query()->create([
            'name' => 'Second Workspace App',
            'kind' => ApplicationRegistration::KIND_LATTE,
            'organization_id' => $organization->organization_id,
            'trusted_origin' => 'https://unique.example.test',
            'redirect_uris' => ['https://unique.example.test/auth/callback'],
            'status' => ApplicationRegistration::STATUS_ACTIVE,
        ]);
    }

    public function test_legacy_control_plane_tables_are_renamed_to_current_names(): void
    {
        $prefix = config('database.table_prefix');
        $legacy = $prefix.'map_setting_defaults';
        $current = $prefix.'setting_defaults';

        DB::statement('ALTER TABLE '.$current.' RENAME TO '.$legacy);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable($legacy));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable($current));

        $migration = include database_path('migrations/2026_07_29_100000_normalize_control_plane_table_names.php');
        $migration->up();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable($legacy));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable($current));
    }

    public function test_login_intent_binds_the_registered_application_for_the_origin(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://customer.example.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');

        $organization = $this->tenancy->createOrganization('Bound Workspace', 'bound-workspace-'.Str::uuid());
        $application = ApplicationRegistration::query()->create([
            'name' => 'Bound App',
            'kind' => ApplicationRegistration::KIND_LATTE,
            'organization_id' => $organization->organization_id,
            'trusted_origin' => 'https://customer.example.test',
            'redirect_uris' => ['https://customer.example.test/app'],
            'status' => ApplicationRegistration::STATUS_ACTIVE,
        ]);

        $this
            ->withHeader('Origin', 'https://customer.example.test')
            ->postJson('/api/v1/auth/login-intents', [
                'redirect_to' => 'https://customer.example.test/app',
            ])
            ->assertOk()
            ->assertSessionHas('pane_v1_application_id', $application->application_id);

        $this
            ->withHeader('Origin', 'https://customer.example.test')
            ->postJson('/api/v1/auth/login-intents', [
                'redirect_to' => 'https://customer.example.test/admin',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'redirect_not_allowed');
    }

    public function test_disabled_application_origin_is_rejected(): void
    {
        ApplicationRegistration::query()->create([
            'name' => 'Disabled App',
            'kind' => ApplicationRegistration::KIND_BURRO,
            'organization_id' => null,
            'trusted_origin' => 'https://burro.example.test',
            'redirect_uris' => ['https://burro.example.test/auth/callback'],
            'status' => ApplicationRegistration::STATUS_DISABLED,
        ]);

        $this
            ->withHeader('Origin', 'https://burro.example.test')
            ->postJson('/api/v1/csrf-cookie')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed');
    }

    public function test_route_organization_must_match_the_bound_latte_application_before_resource_resolution(): void
    {
        Route::middleware(['web', 'auth'])->get(
            '/api/v1/organizations/{organization_id}/application-context-probe',
            fn (Request $request) => response()->json([
                'organization_id' => $request->attributes->get('pane_v1_organization')?->getKey(),
            ])
        );

        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $fixedOrganization = $this->tenancy->createOrganization('Fixed Workspace', 'fixed-workspace-'.Str::uuid());
        $otherOrganization = $this->tenancy->createOrganization('Other Workspace', 'other-workspace-'.Str::uuid());
        $this->tenancy->addOrReactivateMembership(
            $fixedOrganization,
            $user,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        $application = ApplicationRegistration::query()->create([
            'name' => 'Fixed App',
            'kind' => ApplicationRegistration::KIND_LATTE,
            'organization_id' => $fixedOrganization->organization_id,
            'trusted_origin' => 'https://fixed.example.test',
            'redirect_uris' => ['https://fixed.example.test/auth/callback'],
            'status' => ApplicationRegistration::STATUS_ACTIVE,
        ]);

        $this->actingAs($user);

        $this
            ->withSession(['pane_v1_application_id' => $application->application_id])
            ->withHeader('Origin', 'https://fixed.example.test')
            ->getJson('/api/v1/organizations/'.$otherOrganization->organization_id.'/application-context-probe')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'organization_context_mismatch');

        $this
            ->withSession(['pane_v1_application_id' => $application->application_id])
            ->withHeader('Origin', 'https://fixed.example.test')
            ->getJson('/api/v1/organizations/'.$fixedOrganization->organization_id.'/application-context-probe')
            ->assertOk()
            ->assertJsonPath('organization_id', $fixedOrganization->organization_id);
    }
}
