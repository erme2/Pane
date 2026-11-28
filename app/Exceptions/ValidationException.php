<?php

namespace App\Exceptions;

class ValidationException extends \Exception
{
    protected array $errors;

    /**
     * ValidationException is a custom exception for validation errors.
     * The main difference from the default Laravel ValidationException is that
     * this exception returns an array of errors instead of a string.
     */
    /**
     * @param  string|null  $errorCode  machine-readable reason (for example `text_rejected`), or null
     * @param  string|null  $field  the field the reason is about, or null
     */
    public function __construct(array $errors, int $status = 400, protected readonly ?string $errorCode = null, protected readonly ?string $field = null)
    {
        $this->errors = $errors;
        parent::__construct('Validation failed', $status);
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    /**
     * Returns an array of errors.
     */
    public function getErrors(): array
    {
        $return = [];
        foreach ($this->errors as $key => $error) {
            $return[] = [
                'field_name' => $key,
                'message' => $error[0],
            ];
        }

        return $return;
    }
}
