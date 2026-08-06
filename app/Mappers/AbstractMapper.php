<?php

namespace App\Mappers;

use App\Helpers\CoreHelper;
use App\Helpers\MapperHelper;
use App\Helpers\StringHelper;

/**
 * Class AbstractMapper
 * this class will be extended by all mappers
 */
abstract class AbstractMapper
{
    use CoreHelper, MapperHelper, StringHelper;

    const string MAP_TABLES_PREFIX = 'map_';

    const array TABLES = [
        'tables' => 'tables',
        'fields' => 'fields',
        'field_types' => 'field_types',
        'field_validations' => 'field_validations',
        'validation_types' => 'validation_types',
        'users' => 'users',
        'user_types' => 'user_types',
        'organizations' => 'organizations',
        'organization_memberships' => 'organization_memberships',
        'audit_events' => 'audit_events',
        'test_table' => 'test_table',
    ];

    const array FIELD_TYPES = [
        'number' => 1,
        'string' => 2,
        'text' => 3,
        'boolean' => 4,
        'date' => 5,
        'array' => 6,
        'password' => 7,
        'json' => 8,
    ];

    const array VALIDATION_TYPES = [
        'unique' => 1,
        'exists' => 2,
        'max' => 3,
        'min' => 4,
        'email' => 5,
    ];

    const string PASSWORD_REPLACEMENT = '********';

    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
