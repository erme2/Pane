<?php

use App\Mappers\AbstractMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    const CHECK_ERROR_MESSAGES = '(this is required to test error messages)';
    private array $insertKeys = [];
    private string $tableName = 'test_table';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(AbstractMapper::MAP_TABLES_PREFIX.'test_table', function (Blueprint $table) {
            $table->unsignedInteger('table_id');
            $table->string('name');
            $table->text('description');
            $table->boolean('is_active');
            $table->timestamp('created_at');
            $table->json('test_array');
            $table->string('password');
            $table->string('email');
            $table->json('test_json');
        });
        $this->addTableRecord();
        $this->addFieldsRecords();
        $this->addFieldsValidationRecords();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->removeRecords();
        Schema::dropIfExists(AbstractMapper::MAP_TABLES_PREFIX.'test_table');
    }

    private function addTableRecord(): void
    {
        $this->insertKeys['tables'][$this->tableName] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])->insertGetId([
                'name' => $this->tableName,
                'sql_name' => AbstractMapper::MAP_TABLES_PREFIX.$this->tableName,
                'description' => "Just a table to run tests",
            ]);
    }

    private function addFieldsRecords(): void
    {
        $this->insertKeys['fields']['table_id'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables'][$this->tableName],
                'field_type_id' => AbstractMapper::FIELD_TYPES['integer'],
                'name' => 'table_id',
                'sql_name' => null,
                'description' => null,
                'primary' => true,
                'index' => true,
                'nullable' => false,
                'default' => null,
        ]);
        $this->insertKeys['fields']['name'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables'][$this->tableName],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'name',
                'sql_name' => null,
                'description' => null,
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
        ]);
        $this->insertKeys['fields']['description'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables'][$this->tableName],
                'field_type_id' => AbstractMapper::FIELD_TYPES['text'],
                'name' => 'description',
                'sql_name' => null,
                'description' => null,
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['fields']['is_active'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables'][$this->tableName],
                'field_type_id' => AbstractMapper::FIELD_TYPES['boolean'],
                'name' => 'is_active',
                'sql_name' => null,
                'description' => null,
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['fields']['created_at'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables'][$this->tableName],
                'field_type_id' => AbstractMapper::FIELD_TYPES['timestamp'],
                'name' => 'created_at',
                'sql_name' => null,
                'description' => null,
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['fields']['test_array'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables'][$this->tableName],
                'field_type_id' => AbstractMapper::FIELD_TYPES['array'],
                'name' => 'test_array',
                'sql_name' => null,
                'description' => null,
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['fields']['password'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables'][$this->tableName],
                'field_type_id' => AbstractMapper::FIELD_TYPES['password'],
                'name' => 'password',
                'sql_name' => null,
                'description' => null,
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['fields']['email'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables'][$this->tableName],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'email',
                'sql_name' => null,
                'description' => null,
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['fields']['test_json'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables'][$this->tableName],
                'field_type_id' => AbstractMapper::FIELD_TYPES['json'],
                'name' => 'test_json',
                'sql_name' => null,
                'description' => null,
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
            ]);
    }

    private function addFieldsValidationRecords(): void
    {
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['table_id'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
            'value' => null,
            'message' => 'Table ID is required'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['table_id'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
            'value' => 'test_table,table_id',
            'message' => 'Table ID must be unique'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['name'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
            'value' => null,
            'message' => 'Name is required'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['name'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
            'value' => 'test_table,name',
            'message' => 'Name must be unique'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['name'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['min'],
            'value' => 1,
            'message' => 'Name must be at least 1 character long'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['name'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['max'],
            'value' => 255,
            'message' => 'Name must be at most 255 characters long'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['test_array'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
            'value' => null,
            'message' => 'Test array is required'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['test_array'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['array'],
            'value' => null,
            'message' => 'Test array must be an array'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['password'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
            'value' => null,
            'message' => 'Password is required'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['password'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['min'],
            'value' => 8,
            'message' => 'Password must be at least 8 characters long'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['email'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
            'value' => null,
            'message' => 'Email is required'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['email'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
            'value' => AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'].',field_id',
            'message' => 'Email must be unique'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['email'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['email'],
            'value' => 'rfc,dns',
            'message' => 'Email must be a valid email address'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['test_json'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
            'value' => null,
            'message' => 'Test JSON is required'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['test_json'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['json'],
            'value' => null,
            'message' => 'Test JSON must be a valid JSON string'.self::CHECK_ERROR_MESSAGES,
        ]);
    }

    private function removeRecords(): void
    {
        // getting the tableID
        $tableID = DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])
            ->where('name', $this->tableName)
            ->first()
            ->{'table_id'};

        // deleting field validations
        foreach (DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])
            ->where('table_id', $tableID)
            ->get('field_id') as $field) {
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])
                ->where('field_id', $field->field_id)
                ->delete();
        }
        // deleting fields and table
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])
            ->where(['table_id' => $tableID])->delete();
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])
            ->where(['table_id' => $tableID])->delete();
    }

};
