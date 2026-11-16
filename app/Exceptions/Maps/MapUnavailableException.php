<?php

namespace App\Exceptions\Maps;

use RuntimeException;

/**
 * A table map points at a table or field that no longer exists. The map stays
 * unavailable until an admin fixes it (decision on Pane#155); the read and CRUD
 * routes (Pane#154, Pane#156) answer `409 map_unavailable` naming the field.
 */
class MapUnavailableException extends RuntimeException
{
    public const string CODE = 'map_unavailable';

    public function __construct(
        public readonly string $source,
        public readonly string $table,
        public readonly ?string $field,
    ) {
        parent::__construct($field === null
            ? sprintf('The map %s.%s points at a table that no longer exists. Fix the map before using it.', $source, $table)
            : sprintf('The field %s of the map %s.%s no longer exists in the table. Fix the map before using it.', $field, $source, $table));
    }
}
