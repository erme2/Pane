<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use App\Models\Field;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Helper for App/Mappers.
 *
 * @package App\Helpers
 */

trait MapperHelper
{
    use CoreHelper;

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
     * Extract data from model to an object (entity)
     *
     * @param Model $model
     * @return \stdClass
     * @throws SystemException
     * @throws \JsonException
     */
    public function extractFromModel(Model $model): object
    {
        $return = new \stdClass();
        foreach ($this->getFields($this->name) as $field) {
            switch ($field->type) {
                case "array":
                    $return->{$field->name} = json_decode($model->{$field->name}, true, 512, JSON_THROW_ON_ERROR);
                    break;
                case "json":
                    $return->{$field->name} = json_decode($model->{$field->name}, false, 512, JSON_THROW_ON_ERROR);
                    break;
                case "boolean":
                    $return->{$field->name} = (bool) $model->{$field->name};
                    break;
                case "date":
                    $return->{$field->name} = new \DateTime($model->{$field->name});
                    break;
                case "number":
                    $return->{$field->name} = (float) $model->{$field->name};
                    break;
                case "password":
                    $return->{$field->name} = AbstractMapper::PASSWORD_REPLACEMENT;
                    break;
                case "string":
                case "text":
                    $return->{$field->name} = (string) $model->{$field->name};
                    break;
                default:
                    throw new SystemException("Unknown field type: $field->type");
            }
        }
        return $return;
    }

    /**
     * fills the model with data
     *
     * @param Model $model
     * @param array $data
     * @param bool $isCreate
     * @return Model
     * @throws SystemException
     */
    public function fillModel(Model $model, array $data): Model
    {
        foreach ($this->getFields($this->name) as $field) {
            if (isset($data[$field->name]) && (!$field->primary)) {
                switch ($field->type) {
                    case "array":
                        $model->{$field->name} = (string) json_encode($data[$field->name]);
                        break;
                    case "json":
                        $model->{$field->name} = (string) json_encode(
                            json_decode($data[$field->name], false, 512, JSON_THROW_ON_ERROR)
                        );
                        break;
                    case "boolean":
                        $model->{$field->name} = (bool) $data[$field->name];
                        break;
                    case "date":
                        $model->{$field->name} = date('Y-m-d H:i:s', strtotime($data[$field->name]));
                        break;
                    case "number":
                        $model->{$field->name} = (float) $data[$field->name];
                        break;
                    case "password":
                        $model->{$field->name} = bcrypt($data[$field->name]);
                        break;
                    case "string":
                    case "text":
                        $model->{$field->name} = (string) $data[$field->name];
                        break;
                    default:
                        throw new SystemException("Unknown field type: $field->type");
                }
            }
        }
        return $model;
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
    public function getFields(string $name): Collection
    {
        return (new Field())->getFields(Str::snake($name));
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
        foreach ($this->getFields($this->name) as $field) {
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
     * @param bool $withPrimary // if true, primary fields will be included
     * @param bool $justPrimary // if true, only primary fields will be included
     * @return array
     * @throws SystemException
     */
    public function getValidationRules(bool $withPrimary = true, bool $justPrimary = false): array
    {
        $array = [];
        $return = [];

        // if justPrimary is true, withPrimary must be true
        $withPrimary = $justPrimary ? true : $withPrimary;

        /** @var $field Field */
        foreach ($this->getFields($this->name) as $field) {

            if ($field->primary) {
                // skipping primary fields when requested
                if ($withPrimary === false) {
                    continue;
                }
                // primary fields are always required
                $array[$field->name][] = 'required';

                // primary fields are always unique (or exists of justPrimary is true)
                $array[$field->name][] = $justPrimary ?
                    'exists:' . $this->getSqlTableName($this->name) . ',' . $field->name:
                    'unique:' . $this->getSqlTableName($this->name) . ',' . $field->name;
            }

            // if justPrimary is true, only primary fields will be included
            if ($justPrimary) {
                continue;
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

    public function getIndexableFields(): array
    {
        $return = [];
        //* @var $field Field */
        foreach ($this->getFields($this->name) as $field) {
            if ($field->index || $field->primary || $field->sortable || $field->hasValidation('unique')) {
                $return[] = $field->name;
            }
        }
        return $return;
    }
}
