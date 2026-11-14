<?php

namespace App\Support\Maps;

use App\Mappers\AbstractMapper;
use App\Support\PaneTable;
use RuntimeException;

/**
 * Pane's system tables use DB_TABLE_PREFIX; the install's own app tables use
 * APP_TABLE_PREFIX (decision 34). The two must never overlap, or an app table
 * could be mistaken for a protected system table and the other way round.
 *
 * DB_TABLE_PREFIX may be empty, for installs whose Pane tables were created
 * without a prefix (Pane#164). Then no Pane table name may start with
 * APP_TABLE_PREFIX, and APP_TABLE_PREFIX may not start with the legacy `map_`.
 */
class TablePrefixes
{
    /**
     * @throws RuntimeException when the prefixes overlap
     */
    public static function assertNoOverlap(string $systemPrefix, string $appPrefix): void
    {
        if ($appPrefix === '') {
            return;
        }

        if ($systemPrefix === '') {
            self::assertNoClashWithUnprefixedTables($appPrefix);

            return;
        }

        if (str_starts_with($appPrefix, $systemPrefix) || str_starts_with($systemPrefix, $appPrefix)) {
            throw new RuntimeException(sprintf(
                'Unsafe table prefixes: APP_TABLE_PREFIX "%s" overlaps DB_TABLE_PREFIX "%s". Neither may start with the other.',
                $appPrefix,
                $systemPrefix,
            ));
        }
    }

    /** @throws RuntimeException */
    private static function assertNoClashWithUnprefixedTables(string $appPrefix): void
    {
        $legacy = AbstractMapper::MAP_TABLES_PREFIX;
        $clash = str_starts_with($legacy, $appPrefix) || str_starts_with($appPrefix, $legacy) ? $legacy.'*' : null;

        foreach ([...PaneTable::SYSTEM, ...PaneTable::FRAMEWORK] as $table) {
            if ($clash === null && str_starts_with($table, $appPrefix)) {
                $clash = $table;
            }
        }

        if ($clash !== null) {
            throw new RuntimeException(sprintf(
                'Unsafe table prefixes: with an empty DB_TABLE_PREFIX, APP_TABLE_PREFIX "%s" clashes with Pane\'s table "%s".',
                $appPrefix,
                $clash,
            ));
        }
    }
}
