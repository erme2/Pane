<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use App\Models\Field;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Helper for App/Mappers.
 *
 * @package App\Helpers
 */

trait MapperHelper
{

    /**
     * Builds and return a map of the given subject.
     *
     * @param string $subject
     * @param array $data
     * @return Model
     */

    // TODO: implement the map method
    public function map(string $subject, array $data): Model
    {
print_R($data);
die("@ $subject");

    }

    /**
     * checks if the field is required and updates the rules array
     *
     * @param array $rules
     * @param Field $field
     * @return array
     */
    public function checkIfRequired(array $rules, Field $field): array
    {
        if (!$field->nullable) {
            $rules[] = 'required';
        }
        return $rules;
    }

    /**
     * search for additional validation rules and updates the rules array
     *
     * @param array $rules
     * @param Field $field
     * @return array
     * @throws SystemException
     */
    public function getAdditionalValidationRules(array $rules, Field $field): array
    {
        $fieldSpecificRules = $field->getValidationFields($field);

        foreach ($fieldSpecificRules as $validationField) {
            $type = $validationField->getValidationType();

            $rules[] = match ($type->name) {
                "exists", "max", "min", "unique" => $type->name . ':' . $validationField->value,
                "email" => $validationField->value ? 'email:'.$validationField->value : 'email:dns',
                "array", "json", "string", => $type->name,
                default => throw new SystemException("Validation rule not found for $type->name"),
            };
        }

        return $rules;
    }

    /**
     * get the fields of a table
     *
     * @return Collection
     */
    public function getFields(): Collection
    {
        return (new Field())->getFields(Str::snake($this->name));
    }

    /**
     * checks for the field type and updates the rules array
     *
     * @param array $rules
     * @param Field $field
     * @return array
     * @throws SystemException
     */
    public function getTypeRules(array $rules, Field $field): array
    {

        if ($field->type) {
            switch ($field->type) {
                case 'array':
                case 'boolean':
                case 'date':
                case 'email':
                case 'json':
                    $rules[] = $field->type;
                    break;
                case 'number':
                    $rules[] = 'numeric';
                    break;
                case 'password':
                case 'string':
                case 'text':
                    $rules[] = 'string';
                    break;
                default:
                    throw new SystemException("Validation rule not found for $field->type");
                }
            return $rules;
        }
        throw new SystemException('Invalid field type');
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
        foreach ($this->getFields() as $field) {
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
        foreach ($this->getFields() as $field) {

            if ($field->primary) {
                // skipping primary fields when requested
                if ($withPrimary === false) {
                    continue;
                }
                // primary fields are always required
                $array[$field->name][] = 'required';

                // primary fields are always unique
                $array[$field->name][] = 'unique:' . $this->name . ',' . $field->name;

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
}
