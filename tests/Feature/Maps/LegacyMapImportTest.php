<?php

namespace Tests\Feature\Maps;

use App\Models\Maps\MapSource;
use App\Models\Maps\MapTable;
use App\Models\Maps\MapVersion;
use App\Support\PaneTable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyMapImportTest extends TestCase
{
    public function test_every_legacy_system_map_is_imported_as_version_one_by_system_import(): void
    {
        $source = MapSource::query()->where('name', 'install')->firstOrFail();
        $legacy = DB::table(PaneTable::mapName('tables'))
            ->where('sql_name', 'like', PaneTable::prefix().'%')
            ->pluck('name');
        $this->assertNotEmpty($legacy);

        foreach ($legacy as $name) {
            $table = MapTable::query()->where('map_source_id', $source->map_source_id)->where('name', $name)->first();
            $this->assertNotNull($table, "legacy map $name was not imported");
            $this->assertTrue($table->isSystem(), "$name should be a system map");
            $version = MapVersion::query()->where('subject_type', 'table')->where('subject_id', $table->map_table_id)->where('version', 1)->first();
            $this->assertNotNull($version);
            $this->assertSame('system import', $version->author);
        }
    }

    public function test_legacy_fields_keep_their_type_and_validations_by_name(): void
    {
        $table = MapTable::query()->where('name', 'tables')->firstOrFail();
        $legacyTableId = DB::table(PaneTable::mapName('tables'))->where('name', 'tables')->value('table_id');
        $legacyFields = DB::table(PaneTable::mapName('fields'))->where('table_id', $legacyTableId)->orderBy('field_id')->pluck('name')->all();

        $fields = $table->fields()->get();
        $this->assertSame($legacyFields, $fields->pluck('name')->all());
        foreach ($fields as $field) {
            $this->assertContains($field->type, ['number', 'string', 'text', 'boolean', 'date', 'array', 'password', 'json']);
            foreach ($field->validations ?? [] as $validation) {
                $this->assertContains($validation['rule'], ['unique', 'exists', 'max', 'min', 'email']);
            }
        }
    }

    public function test_running_the_import_again_adds_nothing(): void
    {
        $counts = fn (): array => array_map(fn (string $t) => DB::table(PaneTable::name($t))->count(), PaneTable::MAPS);
        $migration = require database_path('migrations/2026_11_13_100100_import_legacy_maps.php');
        // The first run may pick up legacy maps added after the migration ran
        // (the test suite's test_table); a second run must add nothing.
        $migration->up();
        $before = $counts();

        $migration->up();

        $this->assertSame($before, $counts());
    }

    public function test_the_legacy_tables_are_left_untouched(): void
    {
        $this->assertGreaterThan(0, DB::table(PaneTable::mapName('tables'))->count());
        $this->assertGreaterThan(0, DB::table(PaneTable::mapName('fields'))->count());
    }
}
