<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Helpers\ActionHelper;
use App\Mappers\AbstractMapper;
use App\Models\AbstractModel;
use App\Models\Field;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\TestsHelper;

class MapperHelperTest extends TestCase
{
    use ActionHelper, TestsHelper;

    public function test_check_if_required(): void
    {
        $newField = new Field();
        $mapper = new class(self::TEST_TABLE_NAME) extends AbstractMapper {};

        $newField->nullable = true;
        $this->assertEquals([], $mapper->checkIfRequired([], $newField));
        $this->assertEquals(["required"], $mapper->checkIfRequired([], new Field()));

    }

    public function test_extract_from_model()
    {
        $model = $this->getModel(AbstractMapper::TABLES['test_table'])->find(2);
        $mapper = new class(self::TEST_TABLE_NAME) extends AbstractMapper {
            use \App\Helpers\MapperHelper;
        };
        foreach ($mapper->extractFromModel($model) as $key => $value) {
            switch ($key) {
                case "test_json":
                    $this->assertEquals(
                        json_decode(self::UPDATED_VALID_TEST_TABLE_RECORD[$key], false, 512, JSON_THROW_ON_ERROR),
                        $value
                    );
                    break;
                case "password":
                    $this->assertEquals(AbstractMapper::PASSWORD_REPLACEMENT, $value);
                    break;
                case "test_date":
                    $this->assertInstanceOf(\DateTime::class, $value);
                    $this->assertEquals(
                        $model->test_date,
                        $value->format('Y-m-d H:i:s')
                    );
                    break;
                default:
                    $this->assertEquals(self::UPDATED_VALID_TEST_TABLE_RECORD[$key], $value);
            }
        }
    }

    public function test_fill_model()
    {
        $mapper = new class(self::TEST_TABLE_NAME) extends AbstractMapper {
            use \App\Helpers\MapperHelper;
        };
        $model = $this->getModel(AbstractMapper::TABLES['test_table']);
        $res = $mapper->fillModel($model, self::VALID_TEST_TABLE_RECORD);
        $this->assertInstanceOf(AbstractModel::class, $res);
        foreach ($res->toArray() as $field => $value) {
            switch ($field) {
                case 'password':
                    $this->assertIsString($value);
                    $this->assertEquals(60, strlen($value));
                    $this->assertEquals('$', substr($value, 0, 1));
                    break;
                case 'test_json':
                    $this->assertEquals(
                        str_replace(' ', '', self::VALID_TEST_TABLE_RECORD[$field]),
                        $value
                    );
                    break;
                case 'test_date':
                    $testDate = new \DateTime(self::VALID_TEST_TABLE_RECORD[$field]);
                    $this->assertEquals(
                        $testDate->format('Y-m-d H:i:s'),
                        $value
                    );
                    break;
                case 'test_array':
                    $this->assertEquals(
                        '["'.implode('","', self::VALID_TEST_TABLE_RECORD[$field]).'"]',
                        $value
                    );
                    break;
                default:
                    $this->assertEquals(self::VALID_TEST_TABLE_RECORD[$field], $value);
            }

       }
    }

    public function test_get_additional_validation_rules(): void
    {
        $mapper = new class(self::TEST_TABLE_NAME) extends AbstractMapper {};
        $field = new Field();
        $fieldsTable = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'];
        $tablesTable = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'];

        // getting the field_id for test_table.table_id
        $field->test_id = 'invalid';
        $this->assertEquals([], $mapper->getAdditionalValidationRules([], $field));

        $field->field_id = DB::table($fieldsTable)
            ->join($tablesTable,"$fieldsTable.table_id", '=', "$tablesTable.table_id")
            ->where("$fieldsTable.name", '=','table_id')
            ->where("$tablesTable.name", '=',self::TEST_TABLE_NAME)
            ->first()
            ->field_id
        ;
        $this->assertEquals([], $mapper->getAdditionalValidationRules([], $field));

        // getting the field_id for test_table.name
        $field->field_id = DB::table($fieldsTable)
            ->join($tablesTable,"$fieldsTable.table_id", '=', "$tablesTable.table_id")
            ->where("$fieldsTable.name", '=','name')
            ->where("$tablesTable.name", '=',self::TEST_TABLE_NAME)
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

    public function test_get_fields(): void
    {
        // Unknown table
        $tables = [
            'InvalidName' => 0,
            self::TEST_TABLE_NAME => 10,
            'users' => 12,
        ];

        foreach ($tables as $table => $expected) {
            $mapper = new class($table) extends AbstractMapper {};
            $res = $mapper->getFields($table);
            $this->assertInstanceOf(Collection::class, $res);
            $this->assertEquals($expected, $res->count());
        }
    }

    public function test_get_type_rules(): void
    {
        $field = new Field();
        $mapper = new class(self::TEST_TABLE_NAME) extends AbstractMapper {};
        try {
            $mapper->getTypeRules([], $field);
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'Invalid field type', $e->getMessage());
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
        $mapper = new class(self::TEST_TABLE_NAME) extends AbstractMapper {};
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

        // test table with primary key
        $mapper = new class(self::TEST_TABLE_NAME) extends AbstractMapper {};
        $res = $mapper->getValidationRules();
        $this->assertIsArray($res);
        $this->assertEquals(10, count($res));

        // test table without primary key
        $mapper = new class(self::TEST_TABLE_NAME) extends AbstractMapper {};
        $res = $mapper->getValidationRules(false);
        $this->assertIsArray($res);
        $this->assertEquals(9, count($res));

        // test table with just primary key
        $mapper = new class(self::TEST_TABLE_NAME) extends AbstractMapper {};
        $res = $mapper->getValidationRules(true, true);
        $this->assertIsArray($res);
        $this->assertEquals(1, count($res));

        // test table with just primary key (and wrong withPrimary value)
        $mapper = new class(self::TEST_TABLE_NAME) extends AbstractMapper {};
        $res = $mapper->getValidationRules(false, true);
        $this->assertIsArray($res);
        $this->assertEquals(1, count($res));
    }

    public function test_get_indexable_fields(): void
    {
        $mapper = new class(self::TEST_TABLE_NAME) extends AbstractMapper {};
        $res = $mapper->getIndexableFields();
        $this->assertIsArray($res);
        $this->assertEquals(4, count($res));

    }
}
