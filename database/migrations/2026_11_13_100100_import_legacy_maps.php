<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies the legacy `map_*` metadata into the new maps as version 1 of each
 * table, authored by "system import" (Pane#153, answer 8). Safe to run again:
 * a table already in the maps is skipped. The legacy tables are left untouched
 * until #74 retires them. Self-contained on purpose (no app classes beyond the
 * table names), so later code changes can't break this migration.
 */
return new class extends Migration
{
    public const string SOURCE = 'install';

    public const string AUTHOR = 'system import';

    public function up(): void
    {
        $legacyTables = PaneTable::mapName('tables');
        if (! Schema::hasTable($legacyTables)) {
            return;
        }

        $now = now();
        $sourceId = $this->installSource($now);

        $types = DB::table(PaneTable::mapName('field_types'))->pluck('name', 'field_type_id');
        $rules = DB::table(PaneTable::mapName('validation_types'))->pluck('name', 'validation_type_id');

        foreach (DB::table($legacyTables)->orderBy('table_id')->get() as $legacy) {
            $exists = DB::table(PaneTable::name(PaneTable::MAPS_TABLES))
                ->where('map_source_id', $sourceId)
                ->where('name', $legacy->name)
                ->exists();
            if ($exists) {
                continue;
            }

            $fields = [];
            $position = 0;
            foreach (DB::table(PaneTable::mapName('fields'))->where('table_id', $legacy->table_id)->orderBy('field_id')->get() as $field) {
                $validations = DB::table(PaneTable::mapName('field_validations'))
                    ->where('field_id', $field->field_id)
                    ->orderBy('field_validation_id')
                    ->get()
                    ->map(fn (object $v): array => array_filter([
                        'rule' => (string) ($rules[$v->validation_type_id] ?? 'unknown'),
                        'value' => $v->value,
                        'message' => $v->message,
                    ], fn ($value) => $value !== null))
                    ->all();

                $fields[] = [
                    'name' => $field->name,
                    'sql_name' => $field->sql_name,
                    'type' => (string) ($types[$field->field_type_id] ?? 'string'),
                    'description' => $field->description,
                    'format' => $field->format,
                    'primary' => (bool) $field->primary,
                    'index' => (bool) $field->index,
                    'sortable' => (bool) $field->sortable,
                    'nullable' => (bool) $field->nullable,
                    'default' => $field->default,
                    'validations' => $validations,
                    'computed' => null,
                    'ui' => null,
                    'position' => $position++,
                ];
            }

            $snapshot = [
                'source' => self::SOURCE,
                'name' => $legacy->name,
                'sql_name' => $legacy->sql_name,
                'description' => $legacy->description,
                'ui' => null,
                'fields' => $fields,
            ];

            DB::transaction(function () use ($sourceId, $snapshot, $now): void {
                $tableId = DB::table(PaneTable::name(PaneTable::MAPS_TABLES))->insertGetId([
                    'map_source_id' => $sourceId,
                    'name' => $snapshot['name'],
                    'sql_name' => $snapshot['sql_name'],
                    'description' => $snapshot['description'],
                    'ui' => null,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($snapshot['fields'] as $field) {
                    DB::table(PaneTable::name(PaneTable::MAPS_FIELDS))->insert([
                        ...$field,
                        'map_table_id' => $tableId,
                        'default' => $field['default'] === null ? null : json_encode($field['default']),
                        'validations' => json_encode($field['validations']),
                    ]);
                }

                DB::table(PaneTable::name(PaneTable::MAPS_VERSIONS))->insert([
                    'subject_type' => 'table',
                    'subject_id' => $tableId,
                    'version' => 1,
                    'snapshot' => json_encode($snapshot),
                    'diff' => null,
                    'author' => self::AUTHOR,
                    'created_at' => $now,
                ]);
            });
        }
    }

    public function down(): void
    {
        // The import only adds rows to the maps tables, which the previous
        // migration drops on rollback.
    }

    private function installSource(DateTimeInterface $now): int
    {
        $sources = PaneTable::name(PaneTable::MAPS_SOURCES);
        $id = DB::table($sources)->where('name', self::SOURCE)->value('map_source_id');
        if ($id !== null) {
            return (int) $id;
        }

        $snapshot = ['name' => self::SOURCE, 'kind' => 'install', 'description' => "The install's own database"];
        $id = DB::table($sources)->insertGetId([...$snapshot, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table(PaneTable::name(PaneTable::MAPS_VERSIONS))->insert([
            'subject_type' => 'source',
            'subject_id' => $id,
            'version' => 1,
            'snapshot' => json_encode($snapshot),
            'diff' => null,
            'author' => self::AUTHOR,
            'created_at' => $now,
        ]);

        return (int) $id;
    }
};
