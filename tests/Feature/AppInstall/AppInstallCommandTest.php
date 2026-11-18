<?php

namespace Tests\Feature\AppInstall;

use App\Models\Maps\MapField;
use App\Models\Maps\MapSource;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * app:install (Pane#186) against a fixture data folder built in a temp directory.
 */
class AppInstallCommandTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.table_prefix' => 'pane_', 'database.app_table_prefix' => 'fixt_']);
        $this->cleanUp();

        $this->folder = sys_get_temp_dir().'/pane-app-install-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->folder.'/migrations', 0777, true);
        File::makeDirectory($this->folder.'/maps');
        $this->migration('20261117100001_create_lists.sql', 'lists');
        $this->maps(['tables' => [$this->listsMap()]]);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_first_run_creates_tables_and_maps(): void
    {
        $this->artisan('app:install', ['path' => $this->folder])
            ->expectsOutputToContain('1 migration(s) ran.')
            ->expectsOutputToContain('Created maps: fixt_lists_map')
            ->assertSuccessful();

        $this->assertTrue(Schema::hasTable('fixt_lists'));
        $this->assertSame(['20261117100001'], DB::table('fixt_schema_migrations')->pluck('version')->all());
        $map = MapTable::query()->where('name', 'fixt_lists_map')->firstOrFail();
        $this->assertSame('fixt_lists', $map->sql_name);
        $this->assertSame(['id', 'name'], $map->fields()->pluck('name')->all());
        $this->assertSame(1, $map->version);
    }

    public function test_second_run_changes_nothing(): void
    {
        $this->artisan('app:install', ['path' => $this->folder])->assertSuccessful();
        $versions = MapVersion::query()->count();

        $this->artisan('app:install', ['path' => $this->folder])
            ->expectsOutputToContain('0 migration(s) ran.')
            ->expectsOutputToContain('Unchanged maps: fixt_lists_map')
            ->assertSuccessful();

        $this->assertSame($versions, MapVersion::query()->count());
        $this->assertSame(1, DB::table('fixt_schema_migrations')->count());
    }

    public function test_a_new_migration_file_runs_alone(): void
    {
        $this->artisan('app:install', ['path' => $this->folder])->assertSuccessful();
        $this->migration('20261117100002_create_items.sql', 'items');

        $this->artisan('app:install', ['path' => $this->folder])
            ->expectsOutputToContain('1 migration(s) ran.')
            ->assertSuccessful();

        $this->assertTrue(Schema::hasTable('fixt_items'));
        $this->assertSame(2, DB::table('fixt_schema_migrations')->count());
    }

    public function test_a_changed_map_is_updated_as_a_new_version(): void
    {
        $this->artisan('app:install', ['path' => $this->folder])->assertSuccessful();
        $changed = $this->listsMap();
        $changed['description'] = 'Changed';
        $this->maps(['tables' => [$changed]]);

        $this->artisan('app:install', ['path' => $this->folder])
            ->expectsOutputToContain('Updated maps: fixt_lists_map')
            ->assertSuccessful();

        $this->assertSame(2, MapTable::query()->where('name', 'fixt_lists_map')->firstOrFail()->version);
    }

    public function test_an_invalid_maps_file_fails_before_anything_is_written(): void
    {
        $bad = $this->listsMap();
        $bad['fields'][0]['type'] = 'nonsense';
        $this->maps(['tables' => [$bad]]);

        $this->artisan('app:install', ['path' => $this->folder])
            ->expectsOutputToContain('fields.0.type')
            ->assertFailed();

        $this->assertFalse(Schema::hasTable('fixt_lists'));
        $this->assertFalse(Schema::hasTable('fixt_schema_migrations'));
        $this->assertSame(0, MapTable::query()->where('name', 'fixt_lists_map')->count());
    }

    public function test_a_system_map_is_refused_before_anything_is_written(): void
    {
        $system = $this->listsMap();
        $system['sql_name'] = 'pane_users';
        $this->maps(['tables' => [$system]]);

        $this->artisan('app:install', ['path' => $this->folder])
            ->expectsOutputToContain('system table "pane_users"')
            ->assertFailed();

        $this->assertFalse(Schema::hasTable('fixt_lists'));
    }

    public function test_a_name_that_collides_with_an_existing_system_map_is_refused_before_any_write(): void
    {
        $this->artisan('app:install', ['path' => $this->folder])->assertSuccessful();
        $source = MapSource::query()->where('name', 'install')->firstOrFail();
        DB::table((new MapTable)->getTable())->insert([
            'map_source_id' => $source->map_source_id,
            'name' => 'users',
            'sql_name' => 'pane_users',
            'version' => 1,
        ]);

        try {
            $collide = $this->listsMap();
            $collide['name'] = 'users';
            $collide['sql_name'] = 'fixt_items';
            $this->maps(['tables' => [$collide]]);
            $this->migration('20261117100002_create_items.sql', 'items');
            $versions = DB::table('fixt_schema_migrations')->count();

            $this->artisan('app:install', ['path' => $this->folder])
                ->expectsOutputToContain('system map')
                ->assertFailed();

            $this->assertFalse(Schema::hasTable('fixt_items'));
            $this->assertSame($versions, DB::table('fixt_schema_migrations')->count());
        } finally {
            DB::table((new MapTable)->getTable())->where('name', 'users')->where('sql_name', 'pane_users')->delete();
        }
    }

    public function test_a_map_outside_the_app_prefix_is_refused(): void
    {
        $other = $this->listsMap();
        $other['sql_name'] = 'other_lists';
        $this->maps(['tables' => [$other]]);

        $this->artisan('app:install', ['path' => $this->folder])
            ->expectsOutputToContain('outside APP_TABLE_PREFIX')
            ->assertFailed();

        $this->assertFalse(Schema::hasTable('fixt_lists'));
    }

    public function test_the_prefix_must_be_set_and_safe(): void
    {
        foreach (['', 'fixt', 'Fixt_', 'pane_', 'pane_app_'] as $prefix) {
            config(['database.app_table_prefix' => $prefix]);

            $this->artisan('app:install', ['path' => $this->folder])->assertFailed();
        }

        $this->assertFalse(Schema::hasTable('fixt_lists'));
    }

    public function test_status_lists_ran_and_pending_migrations_and_changes_nothing(): void
    {
        $this->artisan('app:install', ['path' => $this->folder])->assertSuccessful();
        $this->migration('20261117100002_create_items.sql', 'items');

        $this->artisan('app:install', ['path' => $this->folder, '--status' => true])
            ->expectsOutputToContain('ran      20261117100001_create_lists.sql')
            ->expectsOutputToContain('pending  20261117100002_create_items.sql')
            ->assertSuccessful();

        $this->assertFalse(Schema::hasTable('fixt_items'));
    }

    public function test_a_folder_without_migrations_fails(): void
    {
        File::delete($this->folder.'/migrations/20261117100001_create_lists.sql');

        $this->artisan('app:install', ['path' => $this->folder])
            ->expectsOutputToContain('no migrations')
            ->assertFailed();
    }

    private function migration(string $file, string $table): void
    {
        File::put(
            $this->folder.'/migrations/'.$file,
            "-- migrate:up\nCREATE TABLE {{prefix}}$table (id INTEGER PRIMARY KEY, name VARCHAR(100));\n\n-- migrate:down\nDROP TABLE {{prefix}}$table;\n",
        );
    }

    /** @param array<string, mixed> $maps */
    private function maps(array $maps): void
    {
        File::put($this->folder.'/maps/fixt.json', json_encode($maps, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function listsMap(): array
    {
        return [
            'name' => 'fixt_lists_map',
            'sql_name' => 'fixt_lists',
            'fields' => [
                ['name' => 'id', 'type' => 'number', 'primary' => true],
                ['name' => 'name', 'type' => 'string', 'validations' => [['rule' => 'max', 'value' => 100]]],
            ],
        ];
    }

    private function cleanUp(): void
    {
        foreach (['fixt_lists', 'fixt_items', 'fixt_schema_migrations'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (MapTable::query()->where('name', 'fixt_lists_map')->get() as $table) {
            MapVersion::query()->where('subject_type', 'table')->where('subject_id', $table->map_table_id)->delete();
            $table->fields()->get()->each(fn (MapField $field) => $field->delete());
            $table->delete();
        }
    }
}
