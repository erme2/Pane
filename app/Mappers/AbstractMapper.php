<?php

namespace App\Mappers;

use App\Exceptions\PaneException;
use App\Helpers\StringHelper;

abstract class AbstractMapper
{
    const MAPPING_TABLES = [
        'tables' => 'pane_tables',
        'fields' => 'pane_fields',
        'field_types' => 'pane_field_types',
        'field_validations' => 'pane_field_validations',
        'field_validation_types' => 'pane_field_validation_types',
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
