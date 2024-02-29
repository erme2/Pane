<?php

namespace Tests\Unit\Models;

use App\Models\FieldValidation;
use App\Models\ValidationType;
use Tests\TestCase;
use Tests\TestsHelper;

class FieldValidationTest extends TestCase
{
    use TestsHelper;

    public function test_get_validation_type(): void
    {
        $this->assertInstanceOf(
            ValidationType::class,
            (new FieldValidation())->find(1)->getValidationType()
        );
    }
}

