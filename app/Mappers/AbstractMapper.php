<?php

namespace App\Mappers;

use App\Exceptions\PaneException;
use App\Helpers\StringHelper;

abstract class AbstractMapper
{
    const MAPPING_TABLES = [
        'tables' => [
            'id' => 1,
            'name' => 'map_tables',
        ],
        'fields' => [
            'id' => 2,
            'name' => 'map_fields',
        ],
        'field_types' => [
            'id' => 3,
            'name' => 'map_field_types',
        ],
        'field_validations' => [
            'id' => 4,
            'name' => 'map_field_validations',
        ],
        'validation_types' => [
            'id' => 5,
            'name' => 'map_validation_types',
        ],
    ];
    const MAPPING_FIELD_TYPES = [
        'integer' => 1,
        'string' => 2,
        'text' => 3,
        'boolean' => 4,
        'timestamp' => 5,
        'array' => 6,
        'password' => 7,
    ];
    const MAPPING_VALIDATION_TYPES = [
        'required' => 1,
        'unique' => 2,
        'exists' => 3,
        'min' => 4,
        'max' => 5,
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
