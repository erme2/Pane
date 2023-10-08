<?php

namespace App\Mappers;

use App\Exceptions\PaneException;

abstract class AbstractMapper
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getValidationMessages(): array
    {
        throw new PaneException("Validation messages not found for $this->name");
    }

    public function getValidationRules(): array
    {
        throw new PaneException("Validation rules not found for $this->name");
    }


}
