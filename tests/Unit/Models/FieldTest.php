<?php

namespace Tests\Unit\Models;

use App\Models\Field;
use Tests\TestCase;
use Tests\TestsHelper;

class FieldTest extends TestCase
{
    use TestsHelper;

    public function test_get_validation_fields()
    {
        $this->markTestIncomplete();
    }

    public function test_get_fields()
    {
        $tables = [
            '' => 0,
            'wrong name' => 0,
            'test_table' => 10,
        ];

        foreach ($tables as $table => $expected) {
            $mapperHelper = new class($table) extends \App\Mappers\AbstractMapper{};
            $fields = $mapperHelper->getFields($table);
            $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $fields);
            $this->assertEquals($expected, $fields->count());
        }
    }

    public function test_get_type_rules()
    {
        $this->markTestIncomplete();
    }

    public function test_validation_messages()
    {
        $this->markTestIncomplete();
    }

    public function test_validation_rules()
    {
        $this->markTestIncomplete();
    }
}
