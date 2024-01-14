<?php

use App\Mappers\AbstractMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestsHelper;


return new class extends Migration
{
    use TestsHelper;
    private array $insertKeys = [];
    private string $tableName = 'test_table';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(AbstractMapper::MAP_TABLES_PREFIX.'test_table', function (Blueprint $table) {
            $table->unsignedInteger('table_id')->autoIncrement();
            $table->string('name');
            $table->text('description');
            $table->boolean('is_active');
            $table->timestamp('test_date');
            $table->json('test_array');
            $table->string('password');
            $table->string('email');
            $table->json('test_json');
        });
        $this->addTableRecord();
        $this->addFieldsRecords();
        $this->addFieldsValidationRecords();
        $this->seedTestTable();
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
                'field_type_id' => AbstractMapper::FIELD_TYPES['number'],
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
                'nullable' => true,
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
                'nullable' => true,
                'default' => 'false',
            ]);
        $this->insertKeys['fields']['test_date'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables'][$this->tableName],
                'field_type_id' => AbstractMapper::FIELD_TYPES['date'],
                'name' => 'test_date',
                'sql_name' => null,
                'description' => null,
                'primary' => false,
                'index' => false,
                'nullable' => true,
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
                'nullable' => true,
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
                'nullable' => true,
                'default' => null,
            ]);
    }

    private function addFieldsValidationRecords(): void
    {
        // name
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['name'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
            'value' => AbstractMapper::MAP_TABLES_PREFIX.'test_table,name',
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
        // password
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['password'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['min'],
            'value' => 8,
            'message' => 'Password must be at least 8 characters long'.self::CHECK_ERROR_MESSAGES,
        ]);
        // email
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['email'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
            'value' => AbstractMapper::MAP_TABLES_PREFIX.'test_table,field_id',
            'message' => 'Email must be unique'.self::CHECK_ERROR_MESSAGES,
        ]);
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            'field_id' => $this->insertKeys['fields']['email'],
            'validation_type_id' => AbstractMapper::VALIDATION_TYPES['email'],
            'value' => null,
            'message' => null,
        ]);
    }

    private function seedTestTable(): void
    {
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.'test_table')->insert([
            'name' => 'Test Table',
            'description' => 'Just a table to run tests',
            'is_active' => true,
            'test_date' => '2021-01-01 00:00:00',
            'test_array' => json_encode(['test' => 'array']),
            'password' => 'password',
            'email' => 'test@email.com',
            'test_json' => json_encode(['test' => 'json']),
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
