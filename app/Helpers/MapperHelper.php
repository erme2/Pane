<?php

namespace App\Helpers;

use App\Mappers\AbstractMapper;

trait MapperHelper
{
    public function getMapper(string $subject): string
    {
        $mapper = new class($subject) extends AbstractMapper {};
        $errors = \Validator::make(
            \request()->all(),
            $mapper->getValidationRules(),
            $mapper->getValidationMessages()
        );
print_r($errors);
die("BBBB");
    }
}
