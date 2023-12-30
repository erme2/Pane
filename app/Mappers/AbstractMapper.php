<?php

namespace App\Mappers;

use App\Exceptions\SystemException;
use App\Helpers\MapperHelper;
use App\Helpers\StringHelper;
use App\Models\Field;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Class AbstractMapper
 * this class will be extended by all mappers
 *
 * @package App\Mappers
 */

abstract class AbstractMapper
{
    use MapperHelper, StringHelper;

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
        'json' => 8,
    ];
    const VALIDATION_TYPES = [
        'required' => 1,
        'unique' => 2,
        'exists' => 3,
        'min' => 4,
        'max' => 5,
        'email' => 6,
        'array' => 7,
        'json' => 8,
    ];
    private string $name;
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Get validation rules or messages for model
     *
     * @param bool $withPrimary
     * @return array
     * @throws SystemException
     */
    public function getValidationRules(bool $withPrimary = true): array
    {
        $array = [];
        $return = [];

        /** @var $field Field */
        foreach ($this->getFields(Str::snake($this->name)) as $field) {

            // skipping primary fields when requested
            if ($withPrimary === false && (bool) $field->primary === true) {
                continue;
            }

            // walking through the validation fields
            foreach ($field->getValidationFields() as $validationField) {
                $type = $validationField->getValidationType();
                $array[$field->name][] = match ($type->name) {
                    "exists", "max", "min", "unique" => $type->name . ':' . $validationField->value,
                    "email" => $validationField->value ? 'email:'.$validationField->value : 'email:rfc',
                    "array", "json", "required" => $type->name,
                    default => throw new SystemException("Validation rule not found for $type->name"),
                };
            }
        }

        foreach ($array as $key => $value) {
            if (!empty($value)) {
                $return[$key] = implode('|', $value);
            }
        }

        return $return;
    }

    /**
     * Get validation messages for model
     *
     * @param bool $withPrimary
     * @return array
     * @throws SystemException
     */
    public function getValidationMessages(bool $withPrimary = true): array
    {
        $return = [];
        foreach ($this->getFields($this->name) as $field) {
            if ($withPrimary === false && (bool) $field->primary === true) {
                continue;
            }
            foreach ($field->getValidationFields() as $validationField) {
                $type = $validationField->getValidationType();
                if (!empty($validationField->message)) {
                    $return["$field->name.$type->name"] = $validationField->message;
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
