<?php

namespace Tests\Unit\Models;

use App\Models\Field;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;
use Tests\TestsHelper;

class FieldTest extends TestCase
{
    use TestsHelper;

    public function test_get_validation_fields()
    {
        $this->assertInstanceOf(Collection::class, (new Field())->getValidationFields((new Field())->first()));
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
}
