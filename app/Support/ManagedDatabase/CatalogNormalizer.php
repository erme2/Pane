<?php

namespace App\Support\ManagedDatabase;

use App\Models\CatalogObject;

/**
 * Turns raw `information_schema` rows (CatalogReader) into catalog objects:
 * the schema, its tables, their columns and relationships, each with a
 * normalized structure, a status and the reasons for that status.
 *
 * A table is `available` only when it meets the phase-one CRUD table
 * contract: a base table with plain identifiers, one single-column primary
 * key, a non-null indexed UUID `pane_membership_id`, `created_at` and
 * `updated_at` timestamps and a nullable `deleted_at`. Anything else is
 * recorded as `incompatible`, with the reasons in `issues`.
 *
 * @phpstan-import-type RawCatalog from CatalogReader
 *
 * @phpstan-type DiscoveredObject array{
 *     key: string,
 *     parent_key: string|null,
 *     kind: string,
 *     name: string,
 *     status: string,
 *     structure: array<string, mixed>,
 *     issues: list<string>,
 * }
 */
class CatalogNormalizer
{
    /** Identifiers Pane can quote and show unambiguously (`table.column`). */
    public const string IDENTIFIER_PATTERN = '/\A[\p{L}\p{N}_$]{1,64}\z/u';

    public const string MEMBERSHIP_COLUMN = 'pane_membership_id';

    private const array TYPES = [
        'tinyint' => 'integer', 'smallint' => 'integer', 'mediumint' => 'integer', 'int' => 'integer',
        'integer' => 'integer', 'bigint' => 'integer', 'year' => 'integer',
        'decimal' => 'decimal', 'numeric' => 'decimal',
        'float' => 'float', 'double' => 'float', 'real' => 'float',
        'char' => 'string', 'varchar' => 'string', 'enum' => 'string',
        'tinytext' => 'text', 'text' => 'text', 'mediumtext' => 'text', 'longtext' => 'text',
        'date' => 'date', 'datetime' => 'datetime', 'timestamp' => 'datetime', 'time' => 'time',
        'json' => 'json', 'uuid' => 'uuid',
    ];

    /**
     * @param  RawCatalog  $raw
     * @return list<DiscoveredObject>
     */
    public function normalize(array $raw, string $database): array
    {
        $schemaKey = CatalogObject::keyFor(CatalogObject::KIND_SCHEMA, $database);
        $objects = [[
            'key' => $schemaKey,
            'parent_key' => null,
            'kind' => CatalogObject::KIND_SCHEMA,
            'name' => $database,
            'status' => CatalogObject::STATUS_AVAILABLE,
            'structure' => [
                'charset' => $this->stringOrNull($raw['schema']['charset'] ?? null),
                'collation' => $this->stringOrNull($raw['schema']['collation_name'] ?? null),
            ],
            'issues' => [],
        ]];

        $columnsByTable = [];
        foreach ($raw['columns'] as $row) {
            $columnsByTable[(string) $row['table_name']][] = $row;
        }

        [$primaryKeys, $indexedFirst] = $this->indexes($raw['indexes']);
        $tableNames = array_map(fn (array $row): string => (string) $row['table_name'], $raw['tables']);

        foreach ($raw['tables'] as $row) {
            $table = (string) $row['table_name'];
            $tableKey = CatalogObject::keyFor(CatalogObject::KIND_TABLE, $table);
            $columns = [];

            foreach ($columnsByTable[$table] ?? [] as $columnRow) {
                $column = $this->column($table, $columnRow, $primaryKeys[$table] ?? []);
                $columns[$column['name']] = $column;
                $objects[] = [
                    'key' => CatalogObject::keyFor(CatalogObject::KIND_COLUMN, $table, $column['name']),
                    'parent_key' => $tableKey,
                    'kind' => CatalogObject::KIND_COLUMN,
                    'name' => $table.'.'.$column['name'],
                    'status' => $column['issues'] === [] ? CatalogObject::STATUS_AVAILABLE : CatalogObject::STATUS_INCOMPATIBLE,
                    'structure' => $column['structure'],
                    'issues' => $column['issues'],
                ];
            }

            $issues = $this->tableIssues($table, (string) ($row['table_type'] ?? ''), $columns, $primaryKeys[$table] ?? [], $indexedFirst[$table] ?? []);
            $objects[] = [
                'key' => $tableKey,
                'parent_key' => $schemaKey,
                'kind' => CatalogObject::KIND_TABLE,
                'name' => $table,
                'status' => $issues === [] ? CatalogObject::STATUS_AVAILABLE : CatalogObject::STATUS_INCOMPATIBLE,
                'structure' => [
                    'table_type' => $this->tableType((string) ($row['table_type'] ?? '')),
                    'engine' => $this->stringOrNull($row['engine'] ?? null),
                    'primary_key' => $primaryKeys[$table] ?? [],
                    'columns' => array_keys($columns),
                ],
                'issues' => $issues,
            ];
        }

        foreach ($this->relationships($raw['foreign_keys'], $database, $tableNames) as $relationship) {
            $objects[] = $relationship;
        }

        return $objects;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{array<string, list<string>>, array<string, list<string>>} primary key columns and leading index columns per table
     */
    private function indexes(array $rows): array
    {
        $primary = [];
        $leading = [];

        foreach ($rows as $row) {
            $table = (string) $row['table_name'];
            $column = (string) $row['column_name'];

            if ((string) $row['index_name'] === 'PRIMARY') {
                $primary[$table][] = $column;
            }
            if ((int) $row['seq'] === 1) {
                $leading[$table][] = $column;
            }
        }

        return [$primary, $leading];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $primaryKey
     * @return array{name: string, type: string, nullable: bool, max_length: int|null, structure: array<string, mixed>, issues: list<string>}
     */
    private function column(string $table, array $row, array $primaryKey): array
    {
        $name = (string) $row['column_name'];
        $dataType = strtolower((string) $row['data_type']);
        $columnType = strtolower((string) ($row['column_type'] ?? $dataType));
        $extra = strtolower((string) ($row['extra'] ?? ''));
        $type = self::TYPES[$dataType] ?? null;
        if ($dataType === 'bit' && $columnType === 'bit(1)') {
            $type = 'boolean';
        }

        $issues = [];
        if (preg_match(self::IDENTIFIER_PATTERN, $name) !== 1) {
            $issues[] = 'unsupported_identifier';
        }
        if ($type === null) {
            $issues[] = 'unsupported_type';
        }

        $nullable = strtoupper((string) $row['is_nullable']) === 'YES';
        $maxLength = $row['max_length'] === null ? null : (int) $row['max_length'];

        return [
            'name' => $name,
            'type' => $type ?? 'unsupported',
            'nullable' => $nullable,
            'max_length' => $maxLength,
            'structure' => [
                'table' => $table,
                'ordinal' => (int) $row['ordinal'],
                'type' => $type ?? 'unsupported',
                'database_type' => $columnType,
                'nullable' => $nullable,
                'max_length' => $maxLength,
                'precision' => $row['numeric_precision'] === null ? null : (int) $row['numeric_precision'],
                'scale' => $row['numeric_scale'] === null ? null : (int) $row['numeric_scale'],
                'primary_key' => in_array($name, $primaryKey, true),
                'auto_increment' => str_contains($extra, 'auto_increment'),
                'generated' => str_contains($extra, 'generated'),
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, array{name: string, type: string, nullable: bool, max_length: int|null, structure: array<string, mixed>, issues: list<string>}>  $columns
     * @param  list<string>  $primaryKey
     * @param  list<string>  $indexedFirst
     * @return list<string>
     */
    private function tableIssues(string $table, string $tableType, array $columns, array $primaryKey, array $indexedFirst): array
    {
        $issues = [];

        if ($this->tableType($tableType) !== 'base_table') {
            $issues[] = 'not_a_base_table';
        }
        if (preg_match(self::IDENTIFIER_PATTERN, $table) !== 1) {
            $issues[] = 'unsupported_identifier';
        }

        if ($primaryKey === []) {
            $issues[] = 'primary_key_missing';
        } elseif (count($primaryKey) > 1) {
            $issues[] = 'primary_key_not_single_column';
        } elseif (($columns[$primaryKey[0]]['issues'] ?? ['missing']) !== []) {
            $issues[] = 'primary_key_unsupported';
        }

        $membership = $columns[self::MEMBERSHIP_COLUMN] ?? null;
        if ($membership === null) {
            $issues[] = 'membership_column_missing';
        } else {
            if ($membership['nullable']) {
                $issues[] = 'membership_column_nullable';
            }
            $isUuid = $membership['type'] === 'uuid' || ($membership['type'] === 'string' && $membership['max_length'] === 36);
            if (! $isUuid) {
                $issues[] = 'membership_column_not_uuid';
            }
            if (! in_array(self::MEMBERSHIP_COLUMN, $indexedFirst, true)) {
                $issues[] = 'membership_column_not_indexed';
            }
        }

        foreach (['created_at', 'updated_at', 'deleted_at'] as $timestamp) {
            $column = $columns[$timestamp] ?? null;
            if ($column === null) {
                $issues[] = $timestamp.'_missing';
            } elseif ($column['type'] !== 'datetime') {
                $issues[] = $timestamp.'_not_a_timestamp';
            }
        }
        if (isset($columns['deleted_at']) && ! $columns['deleted_at']['nullable']) {
            $issues[] = 'deleted_at_not_nullable';
        }

        return $issues;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $tableNames
     * @return list<DiscoveredObject>
     */
    private function relationships(array $rows, string $database, array $tableNames): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['table_name']."\0".(string) $row['constraint_name']][] = $row;
        }

        $objects = [];
        foreach ($grouped as $parts) {
            $first = $parts[0];
            $table = (string) $first['table_name'];
            $constraint = (string) $first['constraint_name'];
            $referencedSchema = (string) $first['referenced_schema'];
            $referencedTable = (string) $first['referenced_table'];

            $issues = [];
            if (preg_match(self::IDENTIFIER_PATTERN, $constraint) !== 1) {
                $issues[] = 'unsupported_identifier';
            }
            if ($referencedSchema !== $database) {
                $issues[] = 'references_another_database';
            } elseif (! in_array($referencedTable, $tableNames, true)) {
                $issues[] = 'referenced_table_missing';
            }

            $objects[] = [
                'key' => CatalogObject::keyFor(CatalogObject::KIND_RELATIONSHIP, $table, $constraint),
                'parent_key' => CatalogObject::keyFor(CatalogObject::KIND_TABLE, $table),
                'kind' => CatalogObject::KIND_RELATIONSHIP,
                'name' => $table.'.'.$constraint,
                'status' => $issues === [] ? CatalogObject::STATUS_AVAILABLE : CatalogObject::STATUS_INCOMPATIBLE,
                'structure' => [
                    'table' => $table,
                    'columns' => array_map(fn (array $row): string => (string) $row['column_name'], $parts),
                    // Only the database name is kept for a reference outside the configured database.
                    'referenced_table' => $referencedSchema === $database ? $referencedTable : null,
                    'referenced_columns' => $referencedSchema === $database
                        ? array_map(fn (array $row): string => (string) $row['referenced_column'], $parts)
                        : [],
                ],
                'issues' => $issues,
            ];
        }

        return $objects;
    }

    private function tableType(string $type): string
    {
        return match (strtoupper($type)) {
            'BASE TABLE' => 'base_table',
            'VIEW', 'SYSTEM VIEW' => 'view',
            'SEQUENCE' => 'sequence',
            default => 'other',
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
