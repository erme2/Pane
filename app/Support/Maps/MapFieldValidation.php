<?php

namespace App\Support\Maps;

use App\Mappers\AbstractMapper;

/**
 * One validation rule of a field in the new maps, shaped like the legacy
 * FieldValidation row the mappers read (value, message, validation type), so
 * the CRUD mapper treats both layers the same.
 */
class MapFieldValidation
{
    public readonly int $validation_type_id;

    public readonly mixed $value;

    public readonly ?string $message;

    private readonly object $type;

    /**
     * @param  array<string, mixed>  $validation  one entry of a map field's `validations`
     */
    public function __construct(array $validation)
    {
        $rule = (string) ($validation['rule'] ?? '');
        $this->validation_type_id = AbstractMapper::VALIDATION_TYPES[$rule] ?? 0;
        $this->value = $validation['value'] ?? null;
        $this->message = isset($validation['message']) ? (string) $validation['message'] : null;
        $this->type = (object) ['name' => $rule];
    }

    public function getValidationType(): object
    {
        return $this->type;
    }
}
