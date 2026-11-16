<?php

namespace App\Support\Maps\Discovery;

use App\Support\Maps\MapProtection;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the real structure of the install's own app tables: the base tables of
 * the connection's current database whose names start with APP_TABLE_PREFIX,
 * and their columns. Reshaped from the catalog reader of PR #151 to scan the
 * install's database through Laravel's schema builder, so it works on SQLite
 * as well as MySQL/MariaDB. It reads metadata only, never rows, and never
 * changes anything.
 *
 * Pane's system tables (MapProtection), other databases on the same server,
 * views and names Pane can't quote are never returned. An empty
 * APP_TABLE_PREFIX means the install has no app tables yet (decision 43).
 *
 * @phpstan-import-type DiscoveredColumn from CatalogNormalizer
 *
 * @phpstan-type DiscoveredTables array<string, array<string, DiscoveredColumn>>
 */
class CatalogReader
{
    public function __construct(private readonly CatalogNormalizer $normalizer) {}

    public static function appPrefix(): string
    {
        return (string) config('database.app_table_prefix', '');
    }

    /**
     * Every app table with its columns, keyed by table then column name, in
     * name order.
     *
     * @return DiscoveredTables
     */
    public function read(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $schema = $db->getSchemaBuilder();

        $names = [];
        foreach ($schema->getTables($schema->getCurrentSchemaName()) as $table) {
            if ($this->isAppTable($table['name'])) {
                $names[] = $table['name'];
            }
        }
        sort($names);

        $tables = [];
        foreach ($names as $name) {
            $tables[$name] = $this->columns($db, $name);
        }

        return $tables;
    }

    /**
     * The columns of one app table, or null when it doesn't exist or isn't an
     * app table.
     *
     * @return array<string, DiscoveredColumn>|null
     */
    public function readTable(string $table, ?string $connection = null): ?array
    {
        $db = DB::connection($connection);

        if (! $this->isAppTable($table) || ! $db->getSchemaBuilder()->hasTable($table)) {
            return null;
        }

        return $this->columns($db, $table);
    }

    public function isAppTable(string $table): bool
    {
        $prefix = self::appPrefix();

        return $prefix !== ''
            && str_starts_with($table, $prefix)
            && preg_match(CatalogNormalizer::IDENTIFIER_PATTERN, $table) === 1
            && ! MapProtection::isSystemTable($table);
    }

    /**
     * @return array<string, DiscoveredColumn>
     */
    private function columns(Connection $db, string $table): array
    {
        $columns = [];
        foreach ($db->getSchemaBuilder()->getColumns($table) as $column) {
            $columns[(string) $column['name']] = $this->normalizer->column($column);
        }

        return $columns;
    }
}
