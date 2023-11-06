<?php

namespace App\Exceptions;

class ValidationException extends \Exception
{
    protected array $errors;

    /**
     * ValidationException is a custom exception for validation errors.
     * The main difference from the default Laravel ValidationException is that
     * this exception returns an array of errors instead of a string.
     *
     * @param array $errors
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct("Validation failed", 400);
    }

    /**
     * Returns an array of errors.
     *
     * @return array
     */
    public function getErrors(): array
    {
        $return  = [];
        foreach($this->errors as $key => $error) {
            $return[] = [
                'field_name' => $key,
                'message' => $error[0],
            ];
        }
        return $return;
    }
}
