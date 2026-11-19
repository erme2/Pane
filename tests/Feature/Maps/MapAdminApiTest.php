<?php

namespace Tests\Feature\Maps;

use App\Models\ApplicationRegistration;
use App\Models\AuditEvent;
use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\AuditEventService;
use App\Services\MapAdminService;
use App\Services\MapStore;
use App\Support\PaneTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The maps admin API (Pane#157): edit table maps, the menu and the function
 * switches as an administrator, with If-Match, history and audit, never DDL.
 */
class MapAdminApiTest extends TestCase
{
    private ApplicationRegistration $application;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.table_prefix' => 'pane_']);
        $this->removeAppMaps();
        Schema::dropIfExists('sweegly_lists');
        Schema::create('sweegly_lists', function ($table): void {
            $table->increments('list_id');
            $table->string('title', 120);
            $table->string('note')->nullable();
        });
        app(MapStore::class)->saveSource(['name' => 'install', 'kind' => 'install', 'description' => null], 'test');
        $this->resetMenu();
        DB::table(PaneTable::name(PaneTable::MAPS_FUNCTIONS))->whereIn('key', ['records', 'members'])->update(['enabled' => true]);
        DB::table(PaneTable::name(PaneTable::MAPS_FUNCTIONS))->where('key', 'dashboard')->update(['enabled' => false]);

        User::query()->delete();
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);
        $this->application = app(ApplicationRegistryService::class)->configuredLatteApplication();
        $this->admin = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
    }

    protected function tearDown(): void
    {
        $this->removeAppMaps();
        Schema::dropIfExists('sweegly_lists');
        $this->resetMenu();

        parent::tearDown();
    }

    /** Back to the migration's seed: one menu item per function, version 1, no menu history. */
    private function resetMenu(): void
    {
        $table = PaneTable::name(PaneTable::MAPS_MENU);
        DB::table($table)->delete();
        foreach (['records', 'members', 'dashboard'] as $position => $key) {
            DB::table($table)->insert([
                'key' => $key, 'label' => ucfirst($key), 'target_type' => 'function', 'target_function' => $key,
                'position' => $position, 'version' => 1,
            ]);
        }
        MapVersion::query()->where('subject_type', 'menu')->delete();
    }

    /** @return array<string, mixed> */
    private function listsMap(): array
    {
        return [
            'description' => 'Shopping lists',
            'ui' => ['label' => 'Lists'],
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'string', 'validations' => [['rule' => 'max', 'value' => '120']]],
            ],
        ];
    }

    private function create(): TestResponse
    {
        return $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $this->listsMap(), ['If-None-Match' => '*']);
    }

    public function test_an_administrator_creates_and_replaces_a_table_map_and_the_table_is_untouched(): void
    {
        $columns = Schema::getColumnListing('sweegly_lists');

        $created = $this->create()->assertCreated()
            ->assertJsonPath('data.type', 'table_map')
            ->assertJsonPath('data.attributes.version', 1)
            ->assertJsonPath('data.attributes.fields.1.name', 'title')
            ->assertHeader('ETag');

        $map = $this->listsMap();
        $map['fields'][] = ['name' => 'note', 'type' => 'text', 'nullable' => true];
        $map['fields'][1]['validations'] = [['rule' => 'max', 'value' => '60']];
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $map, ['If-Match' => (string) $created->headers->get('ETag')])
            ->assertOk()
            ->assertJsonPath('data.attributes.version', 2)
            ->assertJsonCount(3, 'data.attributes.fields');

        $this->assertSame($columns, Schema::getColumnListing('sweegly_lists'));
        $history = $this->send($this->admin, 'get', 'maps/install/sweegly_lists/versions')->assertOk();
        $this->assertSame([2, 1], array_column(array_column($history->json('data'), 'attributes'), 'version'));
        $this->assertSame(['note'], $history->json('data.0.attributes.diff.added'));
        $this->assertSame($this->admin->uuid, $history->json('data.0.attributes.author'));
    }

    public function test_every_edit_is_audited_without_the_diff(): void
    {
        $this->create()->assertCreated();

        $event = AuditEvent::query()->where('action', 'maps.table.save')->where('real_actor_user_id', $this->admin->getKey())->firstOrFail();
        $this->assertSame($this->admin->getKey(), $event->real_actor_user_id);
        $this->assertSame(['source' => 'install', 'table' => 'sweegly_lists'], $event->resource_ids);
        $this->assertSame(['from_version' => 0, 'to_version' => 1], $event->metadata);
    }

    public function test_writes_need_the_right_precondition_and_a_stale_one_conflicts(): void
    {
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $this->listsMap())
            ->assertStatus(428)->assertJsonPath('error.code', 'precondition_required');
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $this->listsMap(), ['If-Match' => '"x"'])
            ->assertStatus(412);
        $etag = (string) $this->create()->assertCreated()->headers->get('ETag');

        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $this->listsMap(), ['If-None-Match' => '*'])
            ->assertStatus(428);
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $this->listsMap())
            ->assertStatus(428);
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $this->listsMap(), ['If-Match' => 'bad'])
            ->assertStatus(400);
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $this->listsMap(), ['If-Match' => '"stale"'])
            ->assertStatus(412)->assertJsonPath('error.code', 'version_conflict');
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $this->listsMap(), ['If-Match' => $etag])->assertOk();
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $this->listsMap(), ['If-Match' => $etag])->assertStatus(412);
    }

    public function test_a_map_may_only_name_columns_and_tables_that_exist(): void
    {
        $map = $this->listsMap();
        $map['fields'][] = ['name' => 'ghost', 'type' => 'string'];
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $map, ['If-None-Match' => '*'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.fields', ['fields.2.name' => 'The column "ghost" does not exist in the table "sweegly_lists".']);

        $this->send($this->admin, 'put', 'maps/install/no_such_table', $this->listsMap(), ['If-None-Match' => '*'])->assertStatus(422);
        $this->assertFalse(Schema::hasTable('no_such_table'));

        // A computed field has no column.
        $map['fields'][2] = ['name' => 'label', 'type' => 'string', 'computed' => ['expression' => 'title']];
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $map, ['If-None-Match' => '*'])->assertCreated();
    }

    public function test_the_body_cannot_rename_the_table_or_use_bad_types(): void
    {
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', [...$this->listsMap(), 'name' => 'other'], ['If-None-Match' => '*'])
            ->assertStatus(422)->assertJsonPath('error.fields.name', 'Must match the table in the path.');

        $map = $this->listsMap();
        $map['fields'][0]['type'] = 'blob';
        $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $map, ['If-None-Match' => '*'])->assertStatus(422);
    }

    public function test_system_maps_are_refused_even_for_administrators(): void
    {
        $system = MapTable::query()->get()->first(fn (MapTable $table) => $table->isSystem());
        $this->assertNotNull($system);
        $source = (string) $system->source?->name;
        $current = $this->send($this->admin, 'get', "maps/$source/$system->name")->assertOk();

        $this->send($this->admin, 'put', "maps/$source/$system->name", ['fields' => []], ['If-Match' => (string) $current->headers->get('ETag')])
            ->assertStatus(409)->assertJsonPath('error.code', 'system_map_protected');
        $this->send($this->admin, 'put', 'maps/install/pane_users', $this->listsMap(), ['If-None-Match' => '*'])
            ->assertStatus(409)->assertJsonPath('error.code', 'system_map_protected');
    }

    public function test_users_who_are_not_administrators_get_403_on_every_route(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $before = MapVersion::query()->count();
        foreach ([
            ['put', 'maps/install/sweegly_lists'], ['get', 'maps/install/sweegly_lists/versions'],
            ['post', 'maps/install/sweegly_lists/versions/1/restore'], ['get', 'maps/menu'], ['put', 'maps/menu'],
            ['get', 'maps/menu/versions'], ['post', 'maps/menu/versions/1/restore'], ['patch', 'maps/functions/records'],
        ] as [$method, $path]) {
            $this->send($user, $method, $path, [], ['If-Match' => '"x"'])->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        }
        $this->assertSame($before, MapVersion::query()->count());
    }

    public function test_restoring_a_version_saves_it_again_as_a_new_one(): void
    {
        $etag = (string) $this->create()->headers->get('ETag');
        $map = $this->listsMap();
        $map['fields'][] = ['name' => 'note', 'type' => 'text'];
        $second = $this->send($this->admin, 'put', 'maps/install/sweegly_lists', $map, ['If-Match' => $etag]);

        $this->send($this->admin, 'post', 'maps/install/sweegly_lists/versions/1/restore')->assertStatus(428);
        $this->send($this->admin, 'post', 'maps/install/sweegly_lists/versions/9/restore', [], ['If-Match' => (string) $second->headers->get('ETag')])
            ->assertNotFound();
        $this->send($this->admin, 'post', 'maps/install/sweegly_lists/versions/1/restore', [], ['If-Match' => (string) $second->headers->get('ETag')])
            ->assertOk()
            ->assertJsonPath('data.attributes.version', 3)
            ->assertJsonCount(2, 'data.attributes.fields');

        $this->assertSame(1, MapVersion::query()->where('subject_type', 'table')->where('version', 3)->value('restored_from'));
        $this->assertDatabaseHas(PaneTable::name(PaneTable::AUDIT_EVENTS), ['action' => 'maps.table.restore']);
    }

    public function test_unknown_source_and_table_versions_are_404(): void
    {
        $this->send($this->admin, 'put', 'maps/nowhere/sweegly_lists', $this->listsMap(), ['If-None-Match' => '*'])->assertNotFound();
        $this->send($this->admin, 'get', 'maps/install/missing/versions')->assertNotFound();
        $this->send($this->admin, 'post', 'maps/install/missing/versions/1/restore', [], ['If-Match' => '"x"'])->assertNotFound();
    }

    public function test_the_menu_is_replaced_versioned_audited_and_restored(): void
    {
        $this->create()->assertCreated();
        $menu = $this->send($this->admin, 'get', 'maps/menu')->assertOk()->assertJsonPath('data.attributes.version', 1);
        $etag = (string) $menu->headers->get('ETag');

        $items = [
            ['key' => 'lists', 'label' => 'Lists', 'icon' => 'list', 'target' => ['type' => 'table', 'source' => 'install', 'table' => 'sweegly_lists'], 'position' => 0],
            ['key' => 'records', 'label' => 'Records', 'target' => ['type' => 'function', 'function' => 'records'], 'parent' => 'lists', 'position' => 1],
        ];
        $saved = $this->send($this->admin, 'put', 'maps/menu', ['items' => $items], ['If-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('data.attributes.version', 2)
            ->assertJsonPath('data.attributes.items.0.key', 'lists')
            ->assertJsonPath('data.attributes.items.1.parent', 'lists');

        $this->send($this->admin, 'put', 'maps/menu', ['items' => $items], ['If-Match' => $etag])->assertStatus(412);
        $this->send($this->admin, 'put', 'maps/menu', ['items' => $items])->assertStatus(428);

        $history = $this->send($this->admin, 'get', 'maps/menu/versions')->assertOk();
        $this->assertSame([2], array_column(array_column($history->json('data'), 'attributes'), 'version'));
        $this->assertContains('lists', $history->json('data.0.attributes.diff.added'));
        $this->assertContains('members', $history->json('data.0.attributes.diff.removed'));
        $this->assertDatabaseHas(PaneTable::name(PaneTable::AUDIT_EVENTS), ['action' => 'maps.menu.save']);

        $this->send($this->admin, 'post', 'maps/menu/versions/2/restore', [], ['If-Match' => (string) $saved->headers->get('ETag')])
            ->assertOk()->assertJsonPath('data.attributes.version', 3);
        $this->send($this->admin, 'post', 'maps/menu/versions/1/restore', [], ['If-Match' => '"menu-3"'])->assertNotFound();
        $this->assertSame(2, MapVersion::query()->where('subject_type', 'menu')->where('version', 3)->value('restored_from'));
    }

    public function test_menu_validation(): void
    {
        $etag = '"menu-1"';
        $table = ['type' => 'table', 'source' => 'install', 'table' => 'unmapped'];
        $function = ['type' => 'function', 'function' => 'records'];
        $bad = [
            'empty' => [[], 'items'],
            'duplicate key' => [[['key' => 'a', 'label' => 'A', 'target' => $function], ['key' => 'a', 'label' => 'B', 'target' => $function]], 'items.1.key'],
            'unmapped table' => [[['key' => 'a', 'label' => 'A', 'target' => $table]], 'items.0.target.table'],
            'unknown function' => [[['key' => 'a', 'label' => 'A', 'target' => ['type' => 'function', 'function' => 'nope']]], 'items.0.target.function'],
            'missing parent' => [[['key' => 'a', 'label' => 'A', 'target' => $function, 'parent' => 'z']], 'items.0.parent'],
            'missing parent after a valid root' => [[
                ['key' => 'a', 'label' => 'A', 'target' => $function],
                ['key' => 'b', 'label' => 'B', 'target' => $function, 'parent' => 'missing'],
            ], 'items.1.parent'],
            'two levels' => [[
                ['key' => 'a', 'label' => 'A', 'target' => $function],
                ['key' => 'b', 'label' => 'B', 'target' => $function, 'parent' => 'a'],
                ['key' => 'c', 'label' => 'C', 'target' => $function, 'parent' => 'b'],
            ], 'items.2.parent'],
            'no label' => [[['key' => 'a', 'label' => ' ', 'target' => $function]], 'items.0.label'],
        ];
        foreach ($bad as $case => [$items, $path]) {
            $response = $this->send($this->admin, 'put', 'maps/menu', ['items' => $items], ['If-Match' => $etag]);
            $this->assertSame(422, $response->getStatusCode(), $case);
            $this->assertArrayHasKey($path, $response->json('error.fields'), $case);
        }
        $this->assertSame(1, (int) DB::table(PaneTable::name(PaneTable::MAPS_MENU))->max('version'));
        $this->assertSame(3, DB::table(PaneTable::name(PaneTable::MAPS_MENU))->count());
    }

    public function test_a_failed_audit_write_rolls_back_a_table_map_save_and_restore(): void
    {
        $this->create()->assertCreated();
        $audit = $this->mock(AuditEventService::class, function ($mock): void {
            $mock->shouldReceive('record')->andThrow(new \RuntimeException('audit down'));
        });
        $service = new MapAdminService(app(MapStore::class), $audit);
        $map = $this->listsMap();
        $map['description'] = 'Changed';

        try {
            $service->saveTable($this->admin, 'install', 'sweegly_lists', $map, 1, null);
            $this->fail('The audit failure should surface.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit down', $e->getMessage());
        }
        $this->assertSame(1, (int) MapTable::query()->where('name', 'sweegly_lists')->value('version'));

        try {
            $service->restoreTable($this->admin, 'install', 'sweegly_lists', 1, 1, null);
            $this->fail('The audit failure should surface.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit down', $e->getMessage());
        }
        $this->assertSame(1, (int) MapTable::query()->where('name', 'sweegly_lists')->value('version'));
    }

    public function test_a_function_is_switched_with_if_match_and_audited_but_not_versioned(): void
    {
        $this->send($this->admin, 'patch', 'maps/functions/dashboard', ['enabled' => true])->assertStatus(428);
        $this->send($this->admin, 'patch', 'maps/functions/dashboard', ['enabled' => true], ['If-Match' => '"dashboard-on"'])->assertStatus(412);
        $this->send($this->admin, 'patch', 'maps/functions/dashboard', ['enabled' => 'yes'], ['If-Match' => '"dashboard-off"'])->assertStatus(422);
        $this->send($this->admin, 'patch', 'maps/functions/nope', ['enabled' => true], ['If-Match' => '"nope-off"'])->assertNotFound();

        $this->send($this->admin, 'patch', 'maps/functions/dashboard', ['enabled' => true], ['If-Match' => '"dashboard-off"'])
            ->assertOk()->assertJsonPath('data.attributes', ['key' => 'dashboard', 'enabled' => true])->assertHeader('ETag', '"dashboard-on"');

        $this->assertTrue((bool) DB::table(PaneTable::name(PaneTable::MAPS_FUNCTIONS))->where('key', 'dashboard')->value('enabled'));
        $event = AuditEvent::query()->where('action', 'maps.function.switch')->firstOrFail();
        $this->assertSame(['function' => 'dashboard', 'enabled' => true], $event->resource_ids);
        $this->assertSame(0, MapVersion::query()->whereNotIn('subject_type', ['source', 'table'])->count());
    }

    public function test_signed_out_requests_are_rejected(): void
    {
        $this->flushHeaders();

        $this->withCsrfToken()->withV1ApplicationSession($this->application)
            ->withHeader('Origin', 'https://latte.test')
            ->putJson('/api/v1/maps/menu', ['items' => []])
            ->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function send(User $as, string $method, string $path, array $body = [], array $headers = []): TestResponse
    {
        $this->flushHeaders();
        $this->actingAs($as);
        $request = $this->withCsrfToken()->withV1ApplicationSession($this->application)->withHeader('Origin', 'https://latte.test');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request->json(strtoupper($method), "/api/v1/$path", $body);
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
}
