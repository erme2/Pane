<?php

namespace App\Mappers;

use App\Exceptions\PaneException;
use App\Helpers\StringHelper;

abstract class AbstractMapper
{
    const MAP_TABLES_PREFIX = 'map_';

    const TABLES = [
        'tables' => 'tables',
        'fields' => 'fields',
        'field_types' => 'field_types',
        'field_validations' => 'field_validations',
        'validation_types' => 'validation_types',
        'users' => 'users',
        'user_types' => 'user_types',
    ];
    const FIELD_TYPES = [
        'integer' => 1,
        'string' => 2,
        'text' => 3,
        'boolean' => 4,
        'timestamp' => 5,
        'array' => 6,
        'password' => 7,
        'email' => 8,
        'json' => 9,
    ];
    const VALIDATION_TYPES = [
        'required' => 1,
        'unique' => 2,
        'exists' => 3,
        'min' => 4,
        'max' => 5,
        'email' => 6,
        'array' => 7,
    ];

    public string $name;

    public function getValidationMessages(): array
    {
        throw new PaneException("Validation messages not found for $this->name");
    }

    public function getValidationRules(): array
    {
        throw new PaneException("Validation rules not found for $this->name");
    }
}
