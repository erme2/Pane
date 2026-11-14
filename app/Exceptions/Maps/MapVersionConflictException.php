<?php

namespace App\Exceptions\Maps;

use RuntimeException;

/** The map changed since the caller read it (the expected version is stale). */
class MapVersionConflictException extends RuntimeException
{
    public function __construct(public readonly int $expected, public readonly int $current)
    {
        parent::__construct("The map is at version $current, not $expected.");
    }
}
