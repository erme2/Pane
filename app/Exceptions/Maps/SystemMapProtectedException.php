<?php

namespace App\Exceptions\Maps;

use RuntimeException;

/** A system map (decision 32) was about to be changed. */
class SystemMapProtectedException extends RuntimeException
{
    public static function forTable(string $name, ?string $sqlName): self
    {
        return new self(sprintf('The map "%s" describes the system table "%s" and cannot be changed.', $name, (string) $sqlName));
    }
}
