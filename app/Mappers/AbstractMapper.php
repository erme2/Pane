<?php

namespace App\Mappers;

use App\Exceptions\SystemException;
use App\Helpers\MapperHelper;
use App\Helpers\StringHelper;
use App\Models\Field;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
        'number' => 1,
        'string' => 2,
        'text' => 3,
        'boolean' => 4,
        'date' => 5,
        'array' => 6,
        'password' => 7,
        'json' => 8,
    ];
    const VALIDATION_TYPES = [
        'unique' => 1,
        'exists' => 2,
        'max' => 3,
        'min' => 4,
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

            if ($field->primary) {
                // skipping primary fields when requested
                if ($withPrimary === false) {
                    continue;
                }
                // primary fields are always required
                $array[$field->name][] = 'required';

                // primary fields are always unique
                $array[$field->name][] = 'unique:' . $this->getTableName(Str::snake($this->name)) . ',' . $field->name;

            }

            // checking if the field is required (!nullable)
            $array[$field->name] = $this->checkIfRequired($array[$field->name] ?? [], $field);

            // checking for some more validation rules
            $array[$field->name] = $this->getAdditionalValidationRules($array[$field->name] ?? [], $field);

            // checking for the field type
            $array[$field->name] = $this->getTypeRules($array[$field->name] ?? [], $field);
        }

        // writing down the rules in laravel format
        foreach ($array as $key => $value) {
            if (!empty($value)) {
                $return[$key] = implode('|', array_unique($value));
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
        foreach ($this->getFields(Str::snake($this->name)) as $field) {
            // skipping primary fields when requested
            if ($withPrimary === false && (bool) $field->primary === true) {
                continue;
            }
            // walking through the validation fields
            foreach ($field->getValidationFields($field) as $validationField) {
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

    public function getTableName(string $name): string
    {
        return DB::table(self::MAP_TABLES_PREFIX.self::TABLES['tables'])
            ->where('name', $name)
            ->first()
            ->{'sql_name'}
        ;
    }
}
