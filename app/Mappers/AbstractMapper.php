<?php

namespace App\Mappers;

use App\Exceptions\PaneException;
use App\Helpers\StringHelper;
use App\Models\Field;
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

    /**
     * Get validation rules or messages for model
     *
     * @param string $what
     * @param bool $withPrimary
     * @return array
     * @throws PaneException
     */
    public function getValidation(string $what = 'rules', bool $withPrimary = true): array
    {
        $array = [];
        $return = [];
        foreach ($this->getFields($this->name) as $field) {
            if ($withPrimary === false && (bool) $field->primary === true) {
                continue;
            }
            foreach ($field->getValidationFields() as $validationField) {
                $type = $validationField->getValidationType();
                if ($what === 'rules') {
                    $array[$field->name][] = match ($type->name) {
                        "exists", "max", "min" => $type->name . ':' . $validationField->value,
                        "required", "unique" => $type->name,
                        default => throw new PaneException("Validation rule not found for $type->name"),
                    };
                }
                if ($what === 'messages' && $validationField->message) {
                    $array["$field->name.$type->name"] = $validationField->message;
                }
            }
        }
        if ($what === 'messages') {
            return $array;
        }
        if ($what === 'rules') {
            foreach ($array as $key => $value) {
                if (!empty($value)) {
                    $return[$key] = implode('|', $value);
                }
            }
        }
        return $return;
    }

    /**
     * get the fields of a table
     *
     * @param string $tableName
     * @return Collection
     */
    public function getFields(string $tableName): Collection
    {
        return (new Field())->getFields($tableName);
    }

}
