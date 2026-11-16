<?php

namespace Tests\Feature\Maps;

use App\Exceptions\Maps\MapUnavailableException;
use App\Models\ApplicationRegistration;
use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\MapDiscovery;
use App\Services\MapStore;
use App\Services\OrganizationTenancyService;
use App\Support\Maps\Discovery\MapAvailability;
use App\Support\PaneTable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The maps discovery report (Pane#155) on SQLite: unmapped, missing and
 * changed app tables and fields, never Pane's system tables, and never a
 * change to maps or tables.
 */
class MapDiscoveryTest extends TestCase
{
    private const array APP_TABLES = ['sweegly_lists', 'sweegly_items', 'sweegly_notes'];

    private MapStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.table_prefix' => 'pane_', 'database.app_table_prefix' => 'sweegly_']);
        $this->store = app(MapStore::class);
        $this->removeAppMaps();
        $this->dropAppTables();
        $this->store->saveSource(['name' => 'install', 'kind' => 'install', 'description' => "The install's own database"], 'test');

        Schema::create('sweegly_lists', function (Blueprint $table): void {
            $table->id('list_id');
            $table->string('title', 120);
            $table->integer('position');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('sweegly_items', function (Blueprint $table): void {
            $table->id('item_id');
            $table->string('label');
        });
        Schema::create('sweegly_notes', function (Blueprint $table): void {
            $table->id('note_id');
            $table->boolean('pinned');
        });
        Schema::create('other_app_table', function (Blueprint $table): void {
            $table->id();
        });
    }

    protected function tearDown(): void
    {
        $this->removeAppMaps();
        $this->dropAppTables();

        parent::tearDown();
    }

    public function test_report_lists_unmapped_missing_and_changed_parts(): void
    {
        $this->store->saveTable('install', [
            'name' => 'lists',
            'sql_name' => 'sweegly_lists',
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'string'],
                // An integer column can't hold a date, and it isn't nullable.
                ['name' => 'position', 'type' => 'date', 'nullable' => true],
                ['name' => 'archived', 'type' => 'boolean'],
            ],
        ], 'test');
        $this->store->saveTable('install', [
            'name' => 'tags',
            'sql_name' => 'sweegly_tags',
            'fields' => [['name' => 'tag_id', 'type' => 'number', 'primary' => true]],
        ], 'test');
        $this->store->saveTable('install', [
            'name' => 'items',
            'sql_name' => 'sweegly_items',
            'fields' => [
                ['name' => 'item_id', 'type' => 'number', 'primary' => true],
                ['name' => 'name', 'sql_name' => 'label', 'type' => 'text'],
            ],
        ], 'test');

        $report = app(MapDiscovery::class)->report();

        $this->assertSame('sweegly_', $report['app_table_prefix']);
        $this->assertSame([[
            'source' => 'install',
            'sql_table' => 'sweegly_notes',
            'fields' => [
                ['sql_field' => 'note_id', 'sql_type' => 'integer', 'nullable' => false],
                ['sql_field' => 'pinned', 'sql_type' => 'tinyint(1)', 'nullable' => false],
            ],
        ]], $report['unmapped_tables']);
        $this->assertSame(
            ['notes', 'created_at', 'updated_at'],
            array_column($report['unmapped_fields'], 'sql_field'),
        );
        $this->assertSame('lists', $report['unmapped_fields'][0]['table']);
        $this->assertSame([
            ['source' => 'install', 'table' => 'lists', 'field' => 'archived', 'sql_table' => 'sweegly_lists', 'sql_field' => 'archived'],
            ['source' => 'install', 'table' => 'tags', 'field' => null, 'sql_table' => 'sweegly_tags', 'sql_field' => null],
        ], $report['missing']);
        $this->assertCount(1, $report['changed']);
        $this->assertSame([
            'source' => 'install', 'table' => 'lists', 'field' => 'position',
            'sql_table' => 'sweegly_lists', 'sql_field' => 'position',
            'sql_type' => 'integer', 'nullable' => false,
            'map_type' => 'date', 'map_nullable' => true,
            'reasons' => ['type', 'nullable'],
        ], $report['changed'][0]);
    }

    public function test_system_tables_and_tables_outside_the_app_prefix_are_never_reported(): void
    {
        $report = app(MapDiscovery::class)->report();
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        $this->assertSame(['sweegly_items', 'sweegly_lists', 'sweegly_notes'], array_column($report['unmapped_tables'], 'sql_table'));
        $this->assertStringNotContainsString('pane_', $encoded);
        $this->assertStringNotContainsString('other_app_table', $encoded);
        $this->assertStringNotContainsString('migrations', $encoded);
        $this->assertSame([], $report['missing'], 'system maps are never reported as missing');
    }

    public function test_an_empty_app_prefix_means_no_app_tables(): void
    {
        config(['database.app_table_prefix' => '']);

        $report = app(MapDiscovery::class)->report();

        $this->assertSame([], $report['unmapped_tables']);
        $this->assertSame([], $report['unmapped_fields']);
        $this->assertSame([], $report['missing']);
        $this->assertSame([], $report['changed']);
    }

    public function test_the_report_never_changes_maps_or_tables(): void
    {
        $this->store->saveTable('install', [
            'name' => 'tags',
            'sql_name' => 'sweegly_tags',
            'fields' => [['name' => 'tag_id', 'type' => 'number', 'primary' => true]],
        ], 'test');
        $before = $this->snapshot();

        app(MapDiscovery::class)->report();
        $this->artisan('pane:maps:discover')->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_map_availability_names_the_missing_field(): void
    {
        $this->store->saveTable('install', [
            'name' => 'lists',
            'sql_name' => 'sweegly_lists',
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'archived', 'type' => 'boolean'],
            ],
        ], 'test');
        $this->store->saveTable('install', [
            'name' => 'items',
            'sql_name' => 'sweegly_items',
            'fields' => [['name' => 'item_id', 'type' => 'number', 'primary' => true]],
        ], 'test');
        $availability = app(MapAvailability::class);

        $availability->assertAvailable($this->tableMap('items'));

        try {
            $availability->assertAvailable($this->tableMap('lists'));
            $this->fail('A map with a missing field must be unavailable.');
        } catch (MapUnavailableException $exception) {
            $this->assertSame('archived', $exception->field);
            $this->assertSame('map_unavailable', MapUnavailableException::CODE);
            $this->assertStringContainsString('archived', $exception->getMessage());
        }

        Schema::drop('sweegly_items');
        $this->expectException(MapUnavailableException::class);
        $availability->assertAvailable($this->tableMap('items'));
    }

    public function test_command_prints_the_report(): void
    {
        $this->store->saveTable('install', [
            'name' => 'tags',
            'sql_name' => 'sweegly_tags',
            'fields' => [['name' => 'tag_id', 'type' => 'number', 'primary' => true]],
        ], 'test');

        $this->artisan('pane:maps:discover')
            ->expectsOutput('App table prefix: sweegly_')
            ->expectsOutput('Unmapped tables: 3')
            ->expectsOutput('  install.tags: table sweegly_tags')
            ->assertSuccessful();

        $this->artisan('pane:maps:discover', ['--json' => true])->assertSuccessful();
    }

    public function test_only_organization_administrators_see_the_report(): void
    {
        $application = $this->latteApplication();
        $organization = $application->organization()->firstOrFail();
        $admin = $this->member($organization, OrganizationMembership::ROLE_ADMINISTRATOR);
        $user = $this->member($organization, OrganizationMembership::ROLE_USER);

        $this->discovery($application, $organization, $admin)
            ->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('data.type', 'map_discovery')
            ->assertJsonPath('data.attributes.app_table_prefix', 'sweegly_')
            ->assertJsonPath('data.attributes.unmapped_tables.0.sql_table', 'sweegly_items')
            ->assertJsonPath('data.attributes.missing', [])
            ->assertJsonPath('data.attributes.changed', []);

        $this->discovery($application, $organization, $user)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'permission_denied');
    }

    private function discovery(ApplicationRegistration $application, Organization $organization, User $as): TestResponse
    {
        $this->flushHeaders();
        $this->actingAs($as);

        return $this->withV1ApplicationSession($application)
            ->withHeader('Origin', 'https://latte.test')
            ->getJson("/api/v1/organizations/{$organization->organization_id}/maps/discovery");
    }

    private function latteApplication(): ApplicationRegistration
    {
        ApplicationRegistration::query()->delete();
        OrganizationMembership::query()->delete();
        Organization::query()->delete();
        User::query()->delete();
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        return app(ApplicationRegistryService::class)->configuredLatteApplication();
    }

    private function member(Organization $organization, string $role): User
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        app(OrganizationTenancyService::class)->addOrReactivateMembership($organization, $user, $role);

        return $user;
    }

    public function test_a_computed_field_without_a_column_is_not_reported(): void
    {
        $this->store->saveTable('install', [
            'name' => 'lists',
            'sql_name' => 'sweegly_lists',
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'string'],
                // Evaluated from other fields, so the table has no physical column for it.
                ['name' => 'label', 'type' => 'string', 'computed' => ['expression' => 'title']],
            ],
        ], 'test');
        $table = $this->tableMap('lists');

        app(MapAvailability::class)->assertAvailable($table);
        $report = app(MapDiscovery::class)->report();

        $this->assertSame([], $report['missing']);
        $this->assertSame([], $report['changed']);
    }

    public function test_a_computed_field_does_not_hide_a_physical_column_with_the_same_name(): void
    {
        $this->store->saveTable('install', [
            'name' => 'lists',
            'sql_name' => 'sweegly_lists',
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'string'],
                // Same name as the physical notes column, but evaluated, so the column stays unmapped.
                ['name' => 'notes', 'type' => 'string', 'computed' => ['expression' => 'title']],
            ],
        ], 'test');

        $report = app(MapDiscovery::class)->report();

        $this->assertContains('notes', array_column($report['unmapped_fields'], 'sql_field'));
    }

    private function tableMap(string $name): MapTable
    {
        return MapTable::query()->where('name', $name)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $rows = fn (string $table, string $key): array => DB::table(PaneTable::name($table))->orderBy($key)->get()
            ->map(fn (object $row): array => (array) $row)->all();

        return [
            'tables' => $rows(PaneTable::MAPS_TABLES, 'map_table_id'),
            'fields' => $rows(PaneTable::MAPS_FIELDS, 'map_field_id'),
            'versions' => DB::table(PaneTable::name(PaneTable::MAPS_VERSIONS))->count(),
            'app_tables' => array_map(fn (string $table): array => Schema::getColumns($table), ['sweegly_lists', 'sweegly_items', 'sweegly_notes']),
        ];
    }

    private function removeAppMaps(): void
    {
        foreach (MapTable::query()->get() as $table) {
            if (! $table->isSystem()) {
                MapVersion::query()->where('subject_type', 'table')->where('subject_id', $table->map_table_id)->delete();
                $table->fields()->get()->each(fn (MapField $field) => $field->delete());
                $table->delete();
            }
        }
    }

    private function dropAppTables(): void
    {
        foreach ([...self::APP_TABLES, 'other_app_table'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
