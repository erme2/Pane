<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use App\Models\Field;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\TestsHelper;

class MapperHelperTest extends TestCase
{
    use TestsHelper;

    public function test_check_if_required(): void
    {
        $newField = new Field();
        $mapper = new class('test_table') extends AbstractMapper {};

        $newField->nullable = true;
        $this->assertEquals([], $mapper->checkIfRequired([], $newField));
        $this->assertEquals(["required"], $mapper->checkIfRequired([], new Field()));

    }

    public function test_get_fields(): void
    {
        // Unknown table
        $mapper = new class('InvalidName') extends AbstractMapper {};
        $res = $mapper->getFields();
        $this->assertInstanceOf(Collection::class, $res);
        $this->assertEquals(0, $res->count());

        // ok
        $mapper = new class('TestTable') extends AbstractMapper {};
        $res = $mapper->getFields();
        $this->assertInstanceOf(Collection::class, $res);
        $this->assertEquals(9, $res->count());
        foreach ($res as $field) {
            $this->assertInstanceOf(Field::class, $field);
        }
    }

    public function test_get_table_name(): void
    {
        // Unknown table
        $mapper = new class('InvalidName') extends AbstractMapper {};
        try {
            $res = $mapper->getTableName();
        } catch (\Exception $e) {
            $this->assertEquals(SystemException::class, get_class($e));
            $this->assertEquals('System Exception: Table for InvalidName (invalid_name) not found', $e->getMessage());
        }

        // test table
        $mapper = new class('TestTable') extends AbstractMapper {};
        $this->assertEquals(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['test_table'], $mapper->getTableName());
    }

    public function test_get_additional_validation_rules(): void
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

    public function test_get_type_rules(): void
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

    public function test_get_validation_messages(): void
    {
        // Unknown table
        $mapper = new class('InvalidName') extends AbstractMapper {};
        $res = $mapper->getValidationMessages();
        $this->assertEquals([], $res);

        // test table
        $mapper = new class('TestTable') extends AbstractMapper {};
        $res = $mapper->getValidationMessages();
        $this->assertIsArray($res);
        $this->assertEquals(5, count($res));
    }

    public function test_get_validation_rules(): void
    {
        // Unknown table
        $mapper = new class('InvalidName') extends AbstractMapper {};
        $res = $mapper->getValidationRules();
        $this->assertEquals([], $res);

        // test table
        $mapper = new class('TestTable') extends AbstractMapper {};
        $res = $mapper->getValidationRules();
        $this->assertIsArray($res);
        $this->assertEquals(9, count($res));
    }
}
