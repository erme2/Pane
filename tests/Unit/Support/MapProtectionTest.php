<?php

namespace Tests\Unit\Support;

use App\Support\Maps\MapProtection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MapProtectionTest extends TestCase
{
    /** @return array<string, array{string, string, string, bool}> system prefix, app prefix, table, is system */
    public static function tables(): array
    {
        return [
            'prefixed: Pane table' => ['pane_', 'sweegly_', 'pane_organizations', true],
            'prefixed: maps table, bare' => ['pane_', 'sweegly_', 'maps_tables', true],
            'prefixed: app table' => ['pane_', 'sweegly_', 'sweegly_lists', false],
            'prefixed: other table' => ['pane_', 'sweegly_', 'products', false],
            'unprefixed: Pane table' => ['', 'sweegly_', 'organizations', true],
            'unprefixed: legacy map table' => ['', 'sweegly_', 'map_tables', true],
            'unprefixed: maps table' => ['', 'sweegly_', 'maps_tables', true],
            'unprefixed: framework table' => ['', 'sweegly_', 'migrations', true],
            'unprefixed: unknown table' => ['', 'sweegly_', 'products', true],
            'unprefixed: app table' => ['', 'sweegly_', 'sweegly_lists', false],
            'unprefixed, no app prefix: any table' => ['', '', 'sweegly_lists', true],
        ];
    }

    #[DataProvider('tables')]
    public function test_system_tables_are_worked_out_from_both_prefixes(string $system, string $app, string $table, bool $expected): void
    {
        config(['database.table_prefix' => $system, 'database.app_table_prefix' => $app]);

        $this->assertSame($expected, MapProtection::isSystemTable($table));
    }
}
