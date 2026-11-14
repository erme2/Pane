<?php

namespace App\Support\ManagedDatabase;

use PDO;

/**
 * Reads the structure of one logical database from MySQL/MariaDB
 * `information_schema`. Only metadata for the configured database is read
 * (every query is filtered by it as a bound parameter), never row data.
 *
 * @phpstan-type RawCatalog array{
 *     schema: array<string, mixed>|null,
 *     tables: list<array<string, mixed>>,
 *     columns: list<array<string, mixed>>,
 *     indexes: list<array<string, mixed>>,
 *     foreign_keys: list<array<string, mixed>>,
 * }
 */
class CatalogReader
{
    public const string SCHEMA_SQL = 'SELECT SCHEMA_NAME AS schema_name, DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation_name'
        .' FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?';

    public const string TABLES_SQL = 'SELECT TABLE_NAME AS table_name, TABLE_TYPE AS table_type, ENGINE AS engine'
        .' FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME';

    public const string COLUMNS_SQL = 'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, ORDINAL_POSITION AS ordinal,'
        .' DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable,'
        .' CHARACTER_MAXIMUM_LENGTH AS max_length, NUMERIC_PRECISION AS numeric_precision, NUMERIC_SCALE AS numeric_scale, EXTRA AS extra'
        .' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION';

    public const string INDEXES_SQL = 'SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique,'
        .' SEQ_IN_INDEX AS seq, COLUMN_NAME AS column_name'
        .' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX';

    public const string FOREIGN_KEYS_SQL = 'SELECT CONSTRAINT_NAME AS constraint_name, TABLE_NAME AS table_name, COLUMN_NAME AS column_name,'
        .' ORDINAL_POSITION AS ordinal, REFERENCED_TABLE_SCHEMA AS referenced_schema, REFERENCED_TABLE_NAME AS referenced_table,'
        .' REFERENCED_COLUMN_NAME AS referenced_column'
        .' FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
        .' ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION';

    /**
     * @return RawCatalog
     */
    public function read(PDO $pdo, string $database): array
    {
        $schema = $this->rows($pdo, self::SCHEMA_SQL, $database);

        return [
            'schema' => $schema[0] ?? null,
            'tables' => $this->rows($pdo, self::TABLES_SQL, $database),
            'columns' => $this->rows($pdo, self::COLUMNS_SQL, $database),
            'indexes' => $this->rows($pdo, self::INDEXES_SQL, $database),
            'foreign_keys' => $this->rows($pdo, self::FOREIGN_KEYS_SQL, $database),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $pdo, string $sql, string $database): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute([$database]);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Some servers return information_schema aliases in upper case.
            $rows[] = array_change_key_case((array) $row, CASE_LOWER);
        }

        return $rows;
    }
}
