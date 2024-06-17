<?php

namespace Tests\Unit\Models;

use App\Mappers\AbstractMapper;
use App\Models\Field;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;
use Tests\TestsHelper;

class FieldTest extends TestCase
{
    use TestsHelper;

    public function test_get_validation_fields()
    {
        $this->assertInstanceOf(Collection::class, (new Field())->getValidationFields());
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

    public function test_has_validation()
    {
        // checking all the fields in test_table
        foreach ((new Field())
            ->select(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'].'.*')
            ->join(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'],
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'].'.table_id', '=',
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'].'.table_id')
            ->where(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'].".name", 'test_table' )
            ->get() as $field) {
            switch ($field->name) {
                case "name":
                case "email":
                    $this->assertEquals(true, $field->hasValidation('unique'));
                    break;
                default:
                    $this->assertEquals(false, $field->hasValidation('unique'));
            }

        }
    }
}
