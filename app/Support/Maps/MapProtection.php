<?php

namespace App\Support\Maps;

use App\Support\PaneTable;

/**
 * Decides which maps are system maps (decision 32, Pane#153 answer 6). It is
 * worked out, never stored, so a stale flag can't unprotect a table: a map is a
 * system map when it is one of the maps' own tables or the table it describes
 * starts with DB_TABLE_PREFIX. With an empty DB_TABLE_PREFIX (Pane#164) every
 * table outside APP_TABLE_PREFIX is a system table, and with both prefixes
 * empty every map is a system map: it fails closed.
 */
class MapProtection
{
    public static function isSystemTable(?string $sqlName): bool
    {
        if ($sqlName === null || $sqlName === '') {
            return false;
        }

        foreach (PaneTable::MAPS as $mapsTable) {
            if ($sqlName === $mapsTable || $sqlName === PaneTable::name($mapsTable)) {
                return true;
            }
        }

        $systemPrefix = PaneTable::prefix();
        if ($systemPrefix !== '') {
            return str_starts_with($sqlName, $systemPrefix);
        }

        $appPrefix = PaneTable::appPrefix();

        return $appPrefix === '' || ! str_starts_with($sqlName, $appPrefix);
    }
}
