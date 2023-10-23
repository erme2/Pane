<?php

namespace App\Mappers;

use App\Exceptions\PaneException;
use App\Helpers\StringHelper;
use App\Models\Field;
use App\Models\Table;
use Illuminate\Database\Eloquent\Collection;

abstract class AbstractMapper
{
    use StringHelper;

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

    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getValidationMessages(): array
    {
        throw new PaneException("Validation messages not found for $this->name");
    }

    public function getValidationRules(): array
    {
        $return = [];
        foreach ($this->getFields($this->name) as $field) {
            $rules = $field->fieldValidations()->get();
            $return[$field->name] = $field->toArray();
            $return[$field->name]['rules'] = $rules->toArray();
        }
        return $return;
    }

    public function getFields(string $tableName): Collection
    {
        return (new Field())->getFields($tableName);
    }

}
