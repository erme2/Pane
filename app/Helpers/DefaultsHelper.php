<?php

namespace App\Helpers;

trait DefaultsHelper
{
    public const PAGINATION_LIMIT = 25;
    public const PAGINATION_MAX = 100;
    public const PAGINATION_OFFSET = 0;
    public const PAGINATION_ORDER = 'asc';

    public function default(string $key)
    {
        // todo replace with dynamic values from settings table
        return match ($key) {
            'PAGINATION_LIMIT' => self::PAGINATION_LIMIT,
            'PAGINATION_MAX' => self::PAGINATION_MAX,
            'PAGINATION_OFFSET' => self::PAGINATION_OFFSET,
            'PAGINATION_ORDER' => self::PAGINATION_ORDER,
            default => null,
        };
    }
}
