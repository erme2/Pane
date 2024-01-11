<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use App\Models\Field;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\TestsHelper;

class MapperHelperTest extends TestCase
{
    use TestsHelper;

    public function test_check_if_required()
    {
        $newField = new Field();
        $mapper = new class('test_table') extends AbstractMapper {};

        $newField->nullable = true;
        $this->assertEquals([], $mapper->checkIfRequired([], $newField));
        $this->assertEquals(["required"], $mapper->checkIfRequired([], new Field()));

    }

    public function test_get_additional_validation_rules()
    {
        $mapper = new class('test_table') extends AbstractMapper {};
        $field = new Field();
        $fieldsTable = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'];
        $tablesTable = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'];

        // getting the field_id for test_table.table_id
        $field->test_id = 'invalid';
        $this->assertEquals([], $mapper->getAdditionalValidationRules([], $field));

        $field->field_id = DB::table($fieldsTable)
            ->join($tablesTable,"$fieldsTable.table_id", '=', "$tablesTable.table_id")
            ->where("$fieldsTable.name", '=','table_id')
            ->where("$tablesTable.name", '=','test_table')
            ->first()
            ->field_id
        ;
        $this->assertEquals([], $mapper->getAdditionalValidationRules([], $field));

        // getting the field_id for test_table.name
        $field->field_id = DB::table($fieldsTable)
            ->join($tablesTable,"$fieldsTable.table_id", '=', "$tablesTable.table_id")
            ->where("$fieldsTable.name", '=','name')
            ->where("$tablesTable.name", '=','test_table')
            ->first()
            ->field_id
        ;
        $this->assertEquals([
                'unique:map_test_table,name',
                'min:1',
                'max:255'
            ],
            $mapper->getAdditionalValidationRules([], $field)
        );
    }

    public function test_get_type_rules()
    {
        $field = new Field();
        $mapper = new class('test_table') extends AbstractMapper {};
        try {
            $mapper->getTypeRules([], $field);
        } catch (SystemException $e) {
            $this->assertEquals('System Exception: Invalid field type', $e->getMessage());
        }
        foreach ([
            'array' => 'array',
            'boolean' => 'boolean',
            'date' => 'date',
            'email' => 'email',
            'json' => 'json',
            'number' => 'numeric',
            'password' => 'string',
            'string' => 'string',
            'text' => 'string',
        ] as $key => $value) {
            $field->type = $key;
            $this->assertEquals([$value], $mapper->getTypeRules([], $field));
        }
    }
}
