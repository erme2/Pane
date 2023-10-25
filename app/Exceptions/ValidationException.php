<?php

namespace App\Exceptions;

class ValidationException extends \Exception
{
    protected array $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct("Validation failed", 400);
    }

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
