<?php

use App\Mappers\AbstractMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    private array $insertKeys = [];
    private string $tableName = 'test_table';
//    private array $

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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->removeRecords();
        Schema::dropIfExists(AbstractMapper::MAP_TABLES_PREFIX.'test_table');
    }

    private function addTableRecord()
    {
        $this->insertKeys['tables'][$this->tableName] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])->insertGetId([
                'name' => $this->tableName,
                'sql_name' => AbstractMapper::MAP_TABLES_PREFIX.$this->tableName,
                'description' => "Just a table to run tests",
            ]);
    }

    private function addFieldsRecords()
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
                'field_type_id' => AbstractMapper::FIELD_TYPES['email'],
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

    // todo add fields validators

    private function removeRecords()
    {
        $tableID = DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])
            ->where('name', $this->tableName)
            ->first()
            ->table_id;
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])
            ->where(['table_id' => $tableID])->delete();
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])
            ->where(['table_id' => $tableID])->delete();
    }

};
