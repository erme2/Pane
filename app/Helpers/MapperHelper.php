<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use App\Models\Field;
use Illuminate\Database\Eloquent\Model;

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

        if ($fieldSpecificRules) {
            foreach ($fieldSpecificRules as $validationField) {
                $type = $validationField->getValidationType();

                $rules[] = match ($type->name) {
                    "exists", "max", "min", "unique" => $type->name . ':' . $validationField->value,
                    "email" => $validationField->value ? 'email:'.$validationField->value : 'email:rfc',
                    "array", "json", "string", => $type->name,
                    default => throw new SystemException("Validation rule not found for $type->name"),
                };
            }
        }
        return $rules;
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
}
