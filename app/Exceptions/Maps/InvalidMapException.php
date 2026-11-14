<?php

namespace App\Exceptions\Maps;

use RuntimeException;

class InvalidMapException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors  field path => message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The map is not valid: '.implode('; ', array_map(
            fn (string $path, string $message): string => "$path: $message",
            array_keys($errors),
            $errors,
        )));
    }
}
