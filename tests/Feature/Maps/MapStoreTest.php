<?php

namespace Tests\Feature\Maps;

use App\Exceptions\Maps\InvalidMapException;
use App\Exceptions\Maps\MapNotFoundException;
use App\Exceptions\Maps\MapVersionConflictException;
use App\Exceptions\Maps\SystemMapProtectedException;
use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Services\MapStore;
use App\Support\PaneTable;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MapStoreTest extends TestCase
{
    private MapStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(MapStore::class);
        // Only app (non-system) maps can be removed; system maps stay as the import left them.
        foreach (MapTable::query()->get() as $table) {
            if (! $table->isSystem()) {
                MapVersion::query()->where('subject_type', 'table')->where('subject_id', $table->map_table_id)->delete();
                $table->fields()->get()->each(fn (MapField $field) => $field->delete());
                $table->delete();
            }
        }
        $this->store->saveSource(['name' => 'install', 'kind' => 'install', 'description' => "The install's own database"], 'test');
    }

    public function test_saving_a_table_map_stores_it_with_version_one(): void
    {
        $version = $this->store->saveTable('install', $this->listsMap(), 'user:1');

        $this->assertSame(1, $version->version);
        $this->assertSame('user:1', $version->author);
        $this->assertNull($version->diff);
        $this->assertSame('install', $version->snapshot['source']);

        $map = $this->store->tableMap('install', 'shopping_lists');
        $this->assertSame('sweegly_shopping_lists', $map['sql_name']);
        $this->assertSame(['label' => 'Shopping lists'], $map['ui']);
        $this->assertSame(['list_id', 'title'], array_column($map['fields'], 'name'));
        $this->assertSame([['rule' => 'max', 'value' => 120]], $map['fields'][1]['validations']);
        $this->assertSame(['widget' => 'text'], $map['fields'][1]['ui']);
    }

    public function test_every_save_adds_a_version_with_a_diff(): void
    {
        $this->store->saveTable('install', $this->listsMap(), 'user:1');
        $changed = $this->listsMap();
        $changed['description'] = 'Lists shared by a household';
        $changed['fields'][1]['nullable'] = true;
        $changed['fields'][] = ['name' => 'archived', 'type' => 'boolean'];

        $version = $this->store->saveTable('install', $changed, 'user:2');

        $this->assertSame(2, $version->version);
        $this->assertSame([null, 'Lists shared by a household'], $version->diff['table']['description']);
        $this->assertSame(['archived'], $version->diff['added']);
        $this->assertSame([false, true], $version->diff['changed']['title']['nullable']);
        $this->assertSame([2, 1], $this->store->tableHistory('install', 'shopping_lists')->pluck('version')->all());
    }

    public function test_restore_copies_an_old_snapshot_forward_as_a_new_version(): void
    {
        $this->store->saveTable('install', $this->listsMap(), 'user:1');
        $changed = $this->listsMap();
        $changed['fields'] = [$changed['fields'][0]];
        $this->store->saveTable('install', $changed, 'user:1');

        $version = $this->store->restoreTable('install', 'shopping_lists', 1, 'user:2');

        $this->assertSame(3, $version->version);
        $this->assertSame(1, $version->restored_from);
        $this->assertSame(['title'], $version->diff['added']);
        $this->assertSame(['list_id', 'title'], array_column($this->store->tableMap('install', 'shopping_lists')['fields'], 'name'));
    }

    public function test_a_stale_expected_version_is_a_conflict(): void
    {
        $this->store->saveTable('install', $this->listsMap(), 'user:1');

        $this->expectException(MapVersionConflictException::class);
        $this->store->saveTable('install', $this->listsMap(), 'user:2', expectedVersion: 0);
    }

    public function test_invalid_maps_are_refused_with_every_error(): void
    {
        try {
            $this->store->saveTable('install', [
                'name' => 'Bad Name',
                'fields' => [
                    ['name' => 'a', 'type' => 'datetime'],
                    ['name' => 'a', 'type' => 'string', 'validations' => [['rule' => 'regex']]],
                ],
            ], 'user:1');
            $this->fail('Expected InvalidMapException');
        } catch (InvalidMapException $e) {
            $this->assertArrayHasKey('name', $e->errors);
            $this->assertArrayHasKey('fields.0.type', $e->errors);
            $this->assertArrayHasKey('fields.1.name', $e->errors);
            $this->assertArrayHasKey('fields.1.validations.0.rule', $e->errors);
        }
    }

    public function test_unknown_sources_and_tables_are_reported(): void
    {
        $this->expectException(MapNotFoundException::class);
        $this->store->saveTable('external_db', $this->listsMap(), 'user:1');
    }

    public function test_system_maps_cannot_be_created_changed_restored_or_deleted(): void
    {
        $imported = MapTable::query()->where('name', 'tables')->firstOrFail();
        $this->assertTrue($imported->isSystem());

        $attempts = [
            'create a map of a system table' => fn () => $this->store->saveTable('install', ['name' => 'users_copy', 'sql_name' => PaneTable::name('users'), 'fields' => []], 'user:1'),
            'create a map of the maps' => fn () => $this->store->saveTable('install', ['name' => 'maps', 'sql_name' => PaneTable::name(PaneTable::MAPS_TABLES), 'fields' => []], 'user:1'),
            'edit an imported system map' => fn () => $this->store->saveTable('install', ['name' => 'tables', 'sql_name' => 'app_tables', 'fields' => []], 'user:1'),
            'restore an imported system map' => fn () => $this->store->restoreTable('install', 'tables', 1, 'user:1'),
            'delete a system map directly' => fn () => $imported->delete(),
            'edit a system field directly' => fn () => $imported->fields()->firstOrFail()->update(['description' => 'changed']),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("Expected SystemMapProtectedException: $label");
            } catch (SystemMapProtectedException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, MapTable::query()->where('name', 'tables')->value('version'));
        $this->assertFalse(MapTable::query()->where('name', 'users_copy')->exists());
    }

    public function test_an_app_map_cannot_be_moved_onto_a_system_table(): void
    {
        $this->store->saveTable('install', $this->listsMap(), 'user:1');
        $moved = $this->listsMap();
        $moved['sql_name'] = PaneTable::name('organizations');

        $this->expectException(SystemMapProtectedException::class);
        $this->store->saveTable('install', $moved, 'user:1');
    }

    public function test_saving_a_map_never_creates_or_changes_tables(): void
    {
        $before = Schema::getTableListing();

        $this->store->saveTable('install', $this->listsMap(), 'user:1');

        $this->assertSame($before, Schema::getTableListing());
        $this->assertFalse(Schema::hasTable('sweegly_shopping_lists'));
    }

    /** @return array<string, mixed> */
    private function listsMap(): array
    {
        return [
            'name' => 'shopping_lists',
            'sql_name' => 'sweegly_shopping_lists',
            'ui' => ['label' => 'Shopping lists'],
            'fields' => [
                ['name' => 'list_id', 'type' => 'number', 'primary' => true],
                ['name' => 'title', 'type' => 'string', 'validations' => [['rule' => 'max', 'value' => 120]], 'ui' => ['widget' => 'text']],
            ],
        ];
    }
}
