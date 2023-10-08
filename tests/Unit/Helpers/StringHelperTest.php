<?php

namespace Tests\Unit\Helpers;


use App\Helpers\StringHelper;
use PHPUnit\Framework\TestCase;

class StringHelperTest extends TestCase
{
    public function test_capital_case(): void
    {
        $testClass = new class {
            use StringHelper;
        };
        $this->assertEquals('Helloworld', $testClass->capitalCase('helloworld'));
        $this->assertEquals('HelloWorld', $testClass->capitalCase('hello-world'));
        $this->assertEquals('HelloWorld', $testClass->capitalCase('hello_world'));
        $this->assertEquals('HelloWorld', $testClass->capitalCase('hello world'));
        $this->assertEquals('Helloworld', $testClass->capitalCase('Helloworld'));
    }

    public function test_pascal_case(): void
    {
        $testClass = new class {
            use StringHelper;
        };
        $this->assertEquals('Helloworld', $testClass->pascalCase('helloworld'));
        $this->assertEquals('HelloWorld', $testClass->pascalCase('hello-world'));
        $this->assertEquals('HelloWorld', $testClass->pascalCase('hello_world'));
        $this->assertEquals('HelloWorld', $testClass->pascalCase('hello world'));
        $this->assertEquals('Helloworld', $testClass->pascalCase('Helloworld'));
    }

    public function test_camel_case(): void
    {
        $testClass = new class {
            use StringHelper;
        };
        $this->assertEquals('helloworld', $testClass->camelCase('helloworld'));
        $this->assertEquals('helloWorld', $testClass->camelCase('hello-world'));
        $this->assertEquals('helloWorld', $testClass->camelCase('hello_world'));
        $this->assertEquals('helloWorld', $testClass->camelCase('hello world'));
        $this->assertEquals('helloworld', $testClass->camelCase('Helloworld'));
    }
}
