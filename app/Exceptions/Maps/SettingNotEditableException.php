<?php

namespace App\Exceptions\Maps;

use RuntimeException;

/** The caller's role is below the one the setting's `editable_by` asks for. */
class SettingNotEditableException extends RuntimeException
{
    public static function forKey(string $key, string $editableBy): self
    {
        return new self("The setting \"$key\" can only be edited by: $editableBy.");
    }
}
