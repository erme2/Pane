<?php

namespace App\Support\Maps;

use App\Support\PaneTable;

/**
 * Decides which maps are system maps (decision 32, Pane#153 answer 6). It is
 * worked out, never stored, so a stale flag can't unprotect a table: a map is a
 * system map when the table it describes starts with DB_TABLE_PREFIX or is one
 * of the maps' own tables. With an empty DB_TABLE_PREFIX every map counts as a
 * system map, which fails closed.
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

        return str_starts_with($sqlName, PaneTable::prefix());
    }
}
