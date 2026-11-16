<?php

namespace Tests\Feature\Maps;

use App\Models\ApplicationRegistration;
use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\MapStore;
use App\Services\OrganizationTenancyService;
use App\Support\Maps\MapProtection;
use App\Support\PaneTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The maps read API (Pane#178): bootstrap and one table map, for organization
 * administrators, standard members and other organizations.
 */
class MapReadApiTest extends TestCase
{
    private ApplicationRegistration $application;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.table_prefix' => 'pane_']);
        $this->removeAppMaps();
        $store = app(MapStore::class);
        $store->saveSource(['name' => 'install', 'kind' => 'install', 'description' => null], 'test');
        $store->saveTable('install', [
            'name' => 'lists',
            'sql_name' => 'sweegly_lists',
            'description' => 'Shopping lists',
            'ui' => ['label' => 'Lists'],
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'string', 'validations' => [['rule' => 'max', 'value' => '120']]],
                ['name' => 'label', 'type' => 'string', 'computed' => ['expression' => 'title']],
            ],
        ], 'test');

        $this->resetMenu();
        $this->application = $this->latteApplication();
        $this->organization = $this->application->organization()->firstOrFail();
    }

    protected function tearDown(): void
    {
        $this->removeAppMaps();

        parent::tearDown();
    }

    public function test_administrators_get_menu_functions_settings_and_every_table_map(): void
    {
        DB::table(PaneTable::name(PaneTable::MAPS_MENU))->insert([
            'key' => 'lists', 'label' => 'Lists', 'target_type' => 'table', 'target_source' => 'install',
            'target_table' => 'lists', 'position' => 5, 'version' => 3,
        ]);
        DB::table(PaneTable::name(PaneTable::MAPS_MENU))->insert([
            'key' => 'ghost', 'label' => 'Ghost', 'target_type' => 'table', 'target_source' => 'install',
            'target_table' => 'not_mapped', 'position' => 6, 'version' => 3,
        ]);

        $response = $this->read($this->admin(), 'maps')->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertHeader('ETag')
            ->assertJsonPath('data.type', 'maps')
            ->assertJsonPath('data.attributes.version', 3)
            ->assertJsonPath('data.attributes.settings', []);

        $attributes = $response->json('data.attributes');
        $this->assertSame(
            [['key' => 'dashboard', 'enabled' => false], ['key' => 'members', 'enabled' => true], ['key' => 'records', 'enabled' => true]],
            $attributes['functions'],
        );
        // The disabled dashboard function and the unmapped table are left out of the menu.
        $this->assertSame(['records', 'members', 'lists'], array_column($attributes['menu'], 'key'));
        $this->assertSame(['type' => 'table', 'source' => 'install', 'table' => 'lists'], $attributes['menu'][2]['target']);
        $this->assertSame(['type' => 'function', 'function' => 'records'], $attributes['menu'][0]['target']);

        $lists = collect($attributes['tables'])->firstWhere('table', 'lists');
        $this->assertSame(['source' => 'install', 'table' => 'lists', 'description' => 'Shopping lists', 'system' => false, 'version' => 1, 'ui' => ['label' => 'Lists']], $lists);
    }

    public function test_members_get_no_tables_and_no_table_menu_items(): void
    {
        DB::table(PaneTable::name(PaneTable::MAPS_MENU))->insert([
            'key' => 'lists', 'label' => 'Lists', 'target_type' => 'table', 'target_source' => 'install',
            'target_table' => 'lists', 'position' => 5,
        ]);

        $this->read($this->member(OrganizationMembership::ROLE_USER), 'maps')->assertOk()
            ->assertJsonPath('data.attributes.tables', [])
            ->assertJsonPath('data.attributes.menu.0.key', 'records')
            ->assertJsonPath('data.attributes.menu.1.key', 'members')
            ->assertJsonCount(2, 'data.attributes.menu');
    }

    public function test_a_menu_child_of_a_hidden_parent_is_left_out(): void
    {
        DB::table(PaneTable::name(PaneTable::MAPS_MENU))->insert([
            'key' => 'child', 'label' => 'Child', 'target_type' => 'function', 'target_function' => 'records',
            'parent_key' => 'dashboard', 'position' => 9,
        ]);

        $keys = array_column($this->read($this->admin(), 'maps')->json('data.attributes.menu'), 'key');

        $this->assertNotContains('child', $keys);
    }

    public function test_table_map_lists_mapped_fields_only_for_administrators(): void
    {
        $response = $this->read($this->admin(), 'maps/install/lists')->assertOk()
            ->assertHeader('ETag')
            ->assertJsonPath('data.type', 'table_map')
            ->assertJsonPath('data.attributes.table', 'lists')
            ->assertJsonPath('data.attributes.system', false);

        $fields = $response->json('data.attributes.fields');
        $this->assertSame(['list_id', 'title', 'label'], array_column($fields, 'name'));
        $this->assertSame([false, false, true], array_column($fields, 'computed'));
        $this->assertSame([['rule' => 'max', 'value' => '120']], $fields[1]['validations']);
        $this->assertArrayNotHasKey('sql_name', $fields[0]);
        $this->assertStringNotContainsString('sweegly_lists', (string) $response->getContent());

        $this->read($this->member(OrganizationMembership::ROLE_USER), 'maps/install/lists')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');
        $this->read($this->admin(), 'maps/install/missing')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');
        $this->read($this->admin(), 'maps/nowhere/lists')->assertNotFound();
    }

    public function test_table_map_hides_server_side_validation_rules(): void
    {
        app(MapStore::class)->saveTable('install', [
            'name' => 'people',
            'sql_name' => 'sweegly_people',
            'fields' => [
                ['name' => 'person_id', 'type' => 'number', 'primary' => true],
                ['name' => 'email', 'type' => 'string', 'validations' => [
                    ['rule' => 'unique', 'value' => 'private_customers,email'],
                    ['rule' => 'exists', 'value' => 'private_accounts,id'],
                    ['rule' => 'max', 'value' => '50'],
                ]],
            ],
        ], 'test');

        $response = $this->read($this->admin(), 'maps/install/people')->assertOk();

        $this->assertSame([['rule' => 'max', 'value' => '50']], $response->json('data.attributes.fields.1.validations'));
        $this->assertStringNotContainsString('private_', (string) $response->getContent());
    }

    public function test_another_organization_cannot_read_these_maps(): void
    {
        $tenancy = app(OrganizationTenancyService::class);
        $other = $tenancy->createOrganization('Other Workspace', 'Other Workspace '.Str::uuid());
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $tenancy->addOrReactivateMembership($other, $user, OrganizationMembership::ROLE_ADMINISTRATOR);
        $this->flushHeaders();
        $this->actingAs($user);

        $this->withV1ApplicationSession($this->application)
            ->withHeader('Origin', 'https://latte.test')
            ->getJson("/api/v1/organizations/{$this->organization->organization_id}/maps")
            ->assertForbidden();
    }

    public function test_the_menu_and_function_tables_are_system_maps(): void
    {
        $this->assertTrue(MapProtection::isSystemTable(PaneTable::name(PaneTable::MAPS_MENU)));
        $this->assertTrue(MapProtection::isSystemTable(PaneTable::name(PaneTable::MAPS_FUNCTIONS)));
    }

    private function read(User $as, string $path): TestResponse
    {
        $this->flushHeaders();
        $this->actingAs($as);

        return $this->withV1ApplicationSession($this->application)
            ->withHeader('Origin', 'https://latte.test')
            ->getJson("/api/v1/organizations/{$this->organization->organization_id}/$path");
    }

    private function admin(): User
    {
        return $this->member(OrganizationMembership::ROLE_ADMINISTRATOR);
    }

    private function member(string $role): User
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        app(OrganizationTenancyService::class)->addOrReactivateMembership($this->organization, $user, $role);

        return $user;
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

    /** Back to the migration's seed: one menu item and one function per Latte function. */
    private function resetMenu(): void
    {
        DB::table(PaneTable::name(PaneTable::MAPS_MENU))->whereNotIn('key', ['records', 'members', 'dashboard'])->delete();
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
        $this->resetMenu();
    }
}
