<?php

namespace App\Support\Maps;

use RuntimeException;

/**
 * Pane's system tables use DB_TABLE_PREFIX; the install's own app tables use
 * APP_TABLE_PREFIX (decision 34). The two must never overlap, or an app table
 * could be mistaken for a protected system table and the other way round.
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

        if (str_starts_with($appPrefix, $systemPrefix) || str_starts_with($systemPrefix, $appPrefix)) {
            throw new RuntimeException(sprintf(
                'Unsafe table prefixes: APP_TABLE_PREFIX "%s" overlaps DB_TABLE_PREFIX "%s". Neither may start with the other.',
                $appPrefix,
                $systemPrefix,
            ));
        }
    }
}
