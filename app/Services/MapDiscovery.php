<?php

namespace App\Services;

use App\Models\Maps\MapSource;
use App\Models\Maps\MapTable;
use App\Support\Maps\Discovery\CatalogNormalizer;
use App\Support\Maps\Discovery\CatalogReader;
use App\Support\Maps\Discovery\MapAvailability;

/**
 * Compares the real structure of the install's own app tables with the maps
 * (decision 30, Pane#155) and reports what differs, so admins can map changes
 * when they are ready. Computed on request and stored nowhere: the report never
 * changes maps or tables.
 *
 * Only maps of `install` sources whose table is under APP_TABLE_PREFIX take
 * part. System maps and Pane's system tables never appear, and external
 * sources are out of scope (parked, decision 10).
 *
 * @phpstan-type DiscoveryReport array{
 *     app_table_prefix: string,
 *     unmapped_tables: list<array{source: string, sql_table: string, fields: list<array{sql_field: string, sql_type: string, nullable: bool}>}>,
 *     unmapped_fields: list<array{source: string, table: string, sql_table: string, sql_field: string, sql_type: string, nullable: bool}>,
 *     missing: list<array{source: string, table: string, field: string|null, sql_table: string, sql_field: string|null}>,
 *     changed: list<array{source: string, table: string, field: string, sql_table: string, sql_field: string, sql_type: string, nullable: bool, map_type: string, map_nullable: bool, reasons: list<string>}>,
 * }
 */
class MapDiscovery
{
    public function __construct(
        private readonly CatalogReader $reader,
        private readonly CatalogNormalizer $normalizer,
        private readonly MapAvailability $availability,
    ) {}

    /**
     * @param  string|null  $connection  the database holding the app tables; the maps always come from the default one
     * @return DiscoveryReport
     */
    public function report(?string $connection = null): array
    {
        $discovered = $this->reader->read($connection);
        $report = [
            'app_table_prefix' => CatalogReader::appPrefix(),
            'unmapped_tables' => [],
            'unmapped_fields' => [],
            'missing' => [],
            'changed' => [],
        ];

        $mapped = [];
        foreach ($this->installTableMaps() as [$source, $table]) {
            $sqlTable = MapAvailability::sqlTable($table);
            $mapped[$sqlTable] = true;
            $columns = $discovered[$sqlTable] ?? null;

            foreach ($this->availability->missing($table, $columns) as $part) {
                $report['missing'][] = ['source' => $source, ...$part];
            }
            if ($columns === null) {
                continue;
            }

            $mappedFields = [];
            foreach ($table->fields as $field) {
                $sqlField = MapAvailability::sqlField($field);
                $mappedFields[$sqlField] = true;
                $column = $columns[$sqlField] ?? null;
                if ($column === null || $field->computed !== null) {
                    continue;
                }

                $reasons = $this->normalizer->differences($field->type, $field->nullable, $column);
                if ($reasons !== []) {
                    $report['changed'][] = [
                        'source' => $source, 'table' => $table->name, 'field' => $field->name,
                        'sql_table' => $sqlTable, 'sql_field' => $sqlField,
                        'sql_type' => $column['sql_type'], 'nullable' => $column['nullable'],
                        'map_type' => $field->type, 'map_nullable' => $field->nullable,
                        'reasons' => $reasons,
                    ];
                }
            }

            foreach ($columns as $sqlField => $column) {
                if (! isset($mappedFields[$sqlField])) {
                    $report['unmapped_fields'][] = [
                        'source' => $source, 'table' => $table->name, 'sql_table' => $sqlTable, 'sql_field' => (string) $sqlField,
                        'sql_type' => $column['sql_type'], 'nullable' => $column['nullable'],
                    ];
                }
            }
        }

        $installSource = $this->installSourceName();
        foreach ($discovered as $sqlTable => $columns) {
            if (isset($mapped[$sqlTable])) {
                continue;
            }

            $fields = [];
            foreach ($columns as $sqlField => $column) {
                $fields[] = ['sql_field' => (string) $sqlField, 'sql_type' => $column['sql_type'], 'nullable' => $column['nullable']];
            }
            $report['unmapped_tables'][] = ['source' => $installSource, 'sql_table' => (string) $sqlTable, 'fields' => $fields];
        }

        return $report;
    }

    /**
     * @return list<array{0: string, 1: MapTable}> source name and table map, in source then table name order
     */
    private function installTableMaps(): array
    {
        $maps = [];
        $sources = MapSource::query()->where('kind', 'install')->orderBy('name')->get();
        foreach ($sources as $source) {
            $tables = $source->tables()->with('fields')->orderBy('name')->get();
            foreach ($tables as $table) {
                if (! $table->isSystem() && $this->reader->isAppTable(MapAvailability::sqlTable($table))) {
                    $maps[] = [$source->name, $table];
                }
            }
        }

        return $maps;
    }

    /** Unmapped tables belong to the install's own database, the `install` source. */
    private function installSourceName(): string
    {
        $name = MapSource::query()->where('kind', 'install')->orderBy('map_source_id')->value('name');

        return is_string($name) ? $name : 'install';
    }
}
