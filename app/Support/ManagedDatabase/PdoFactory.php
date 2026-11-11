<?php

namespace App\Support\ManagedDatabase;

use PDO;

/** Opens PDO connections; swapped out in tests so no real network is used. */
class PdoFactory
{
    /**
     * @param  array<int, mixed>  $options
     */
    public function make(string $dsn, ?string $username, #[\SensitiveParameter] ?string $password, array $options): PDO
    {
        return new PDO($dsn, $username, $password, $options);
    }
}
