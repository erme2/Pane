<?php

use App\Mappers\AbstractMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $insertKeys = [];


    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->buildMainStructure();
        $this->tablesInserts();
        $this->fieldTypesInserts();
        $this->fieldsInsertsTables();
        $this->fieldsInsertsFields();
        $this->fieldsInsertsFieldTypes();
        $this->fieldsInsertsFieldValidations();
        $this->fieldsInsertsValidationRules();
        $this->fieldValidationTypesInserts();
        $this->fieldValidationTablesInserts();
        $this->fieldValidationFieldsInserts();
        $this->fieldValidationFieldTypesInserts();
        $this->fieldValidationValidationTypesInserts();
        $this->validationTypesInserts();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['validation_types']);
        Schema::dropIfExists(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations']);
        Schema::dropIfExists(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_types']);
        Schema::dropIfExists(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields']);
        Schema::dropIfExists(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables']);
    }

    private function buildMainStructure(): void
    {
        Schema::create(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'],
            function (Blueprint $table) {
            $table->unsignedInteger('table_id')->autoIncrement();
            $table->string('name', 255)->index();
            $table->string('sql_name', 255)->nullable();
            $table->text('description')->nullable();
        });
        Schema::create(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'],
            function (Blueprint $table) {
            $table->unsignedBigInteger('field_id')->autoIncrement();
            $table->unsignedInteger('table_id')->index();
            $table->unsignedSmallInteger('field_type_id')->index();
            $table->string('name', 255);
            $table->string('sql_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->boolean('primary')->default(false);
            $table->boolean('index')->default(false);
            $table->boolean('nullable')->default(false);
            $table->string('default')->nullable();
        });
        Schema::create(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_types'],
            function (Blueprint $table) {
            $table->unsignedSmallInteger('field_type_id')->autoIncrement();
            $table->string('name', 255);
        });
        Schema::create(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'],
            function (Blueprint $table) {
            $table->unsignedInteger('field_validation_id')->autoIncrement();
            $table->unsignedBigInteger('field_id')->index();
            $table->unsignedSmallInteger('validation_type_id');
            $table->string('value', 255)->nullable();
        });
        Schema::create(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['validation_types'],
            function (Blueprint $table) {
            $table->unsignedInteger('validation_type_id')->autoIncrement();
            $table->string('name', 255);
        });
    }

    private function tablesInserts(): void
    {
        // tables
        $this->insertKeys['tables']['tables'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])->insert([
            'name' => AbstractMapper::TABLES['tables'],
            'sql_name' => AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'],
            'description' => "pane system table for storing tables",
        ]);
        $this->insertKeys['tables']['fields'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])->insert([
            'name' => AbstractMapper::TABLES['fields'],
            'sql_name' => AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'],
            'description' => "pane system table for storing fields",
        ]);
        $this->insertKeys['tables']['field_types'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])->insert([
            'name' => AbstractMapper::TABLES['field_types'],
            'sql_name' => AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_types'],
            'description' => "pane system table for storing field types",
        ]);
        $this->insertKeys['tables']['field_validations'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])->insert([
            'name' => AbstractMapper::TABLES['field_validations'],
            'sql_name' => AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'],
            'description' => "pane system table for storing field validations rules",
        ]);
        $this->insertKeys['tables']['validation_types'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])->insert([
            'name' => AbstractMapper::TABLES['validation_types'],
            'sql_name' => AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['validation_types'],
            'description' => "pane system table for storing validations rule types",
        ]);
    }

    private function fieldTypesInserts(): void
    {
        // fields types
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_types'])->insert([
            [
                'field_type_id' => AbstractMapper::FIELD_TYPES['integer'],
                'name' => 'integer',
            ],
            [
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'string',
            ],
            [
                'field_type_id' => AbstractMapper::FIELD_TYPES['text'],
                'name' => 'text',
            ],
            [
                'field_type_id' => AbstractMapper::FIELD_TYPES['boolean'],
                'name' => 'boolean',
            ],
            [
                'field_type_id' => AbstractMapper::FIELD_TYPES['timestamp'],
                'name' => 'timestamp',
            ],
            [
                'field_type_id' => AbstractMapper::FIELD_TYPES['array'],
                'name' => 'array',
            ],
            [
                'field_type_id' => AbstractMapper::FIELD_TYPES['password'] ,
                'name' => 'password',
            ],
        ]);
    }

    private function fieldsInsertsTables(): void
    {
        // FIELDS
        $this->insertKeys['fields']['tables']['table_id'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['tables'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['integer'],
                'name' => 'table_id',
                'primary' => true,
                'index' => true,
                'nullable' => false,
            ]);
        $this->insertKeys['fields']['tables']['name'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['tables'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'name',
                'primary' => false,
                'index' => true,
                'nullable' => false,
            ]);
        $this->insertKeys['fields']['tables']['sql_name'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['tables'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'sql_name',
                'primary' => false,
                'index' => false,
                'nullable' => true,
            ]);
        $this->insertKeys['fields']['tables']['description'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['tables'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['text'],
                'name' => 'description',
                'primary' => false,
                'index' => false,
                'nullable' => true,
            ]);
    }

    private function fieldsInsertsFields(): void
    {
        // fields
        $this->insertKeys['fields']['fields']['field_id'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['fields'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['integer'],
                'name' => 'field_id',
                'primary' => true,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['fields']['fields']['table_id'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['fields'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['integer'],
                'name' => 'table_id',
                'primary' => false,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['fields']['fields']['field_type_id'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['fields'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['integer'],
                'name' => 'field_type_id',
                'primary' => false,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['fields']['fields']['name'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['fields'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'name',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['fields']['fields']['sql_name'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['fields'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'sql_name',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
        $this->insertKeys['fields']['fields']['description'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['fields'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['text'],
                'name' => 'description',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
        $this->insertKeys['fields']['fields']['primary'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['fields'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['boolean'],
                'name' => 'primary',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => 'false',
            ]);
        $this->insertKeys['fields']['fields']['index'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['fields'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['boolean'],
                'name' => 'index',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => 'false',
            ]);
        $this->insertKeys['fields']['fields']['nullable'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['fields'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['boolean'],
                'name' => 'nullable',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => 'false',
            ]);
        $this->insertKeys['fields']['fields']['default'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['fields'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'default',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
    }

    private function fieldsInsertsFieldTypes(): void
    {
        // field types
        $this->insertKeys['fields']['field_types']['field_type_id'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['field_types'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['integer'],
                'name' => 'field_type_id',
                'primary' => true,
                'index' => true,
            ]);
        $this->insertKeys['fields']['field_types']['name'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['field_types'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'name',
                'primary' => false,
                'index' => false,
            ]);
    }

    private function fieldsInsertsFieldValidations(): void
    {
        // field validations
        $this->insertKeys['fields']['field_validations']['field_validation_id'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['field_validations'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['integer'],
                'name' => 'field_validation_id',
                'primary' => true,
                'index' => true,
                'nullable' => false,
            ]);
        $this->insertKeys['fields']['field_validations']['field_id'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['field_validations'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['integer'],
                'name' => 'field_id',
                'primary' => false,
                'index' => true,
                'nullable' => false,
            ]);
        $this->insertKeys['fields']['field_validations']['validation_type_id'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['field_validations'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['integer'],
                'name' => 'validation_type_id',
                'primary' => false,
                'index' => false,
                'nullable' => false,
            ]);
        $this->insertKeys['fields']['field_validations']['value'] =
            DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['field_validations'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'value',
                'primary' => false,
                'index' => false,
                'nullable' => true,
            ]);
    }

    private function fieldsInsertsValidationRules(): void
    {
        // validation types
        $this->insertKeys['fields']['validation_types']['validation_type_id'] =
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['validation_types'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['integer'],
                'name' => 'validation_type_id',
                'primary' => true,
                'index' => true,
            ]);
        $this->insertKeys['fields']['validation_types']['name'] =
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'])->insertGetId([
                'table_id' => $this->insertKeys['tables']['validation_types'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'name',
                'primary' => false,
                'index' => false,
            ]);
    }

    private function fieldValidationTypesInserts(): void
    {
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['validation_types'])->insert([
            [
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'name' => 'required',
            ],
            [
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
                'name' => 'unique',
            ],
            [
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'name' => 'exists',
            ],
            [
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['min'],
                'name' => 'min',
            ],
            [
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['max'],
                'name' => 'max',
            ],
        ]);
    }

    private function fieldValidationTablesInserts(): void
    {
        // field validations
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            [
                'field_id' => $this->insertKeys['fields']['tables']['table_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['tables']['table_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::TABLES['tables'].",table_id",
            ],
            [
                'field_id' => $this->insertKeys['fields']['tables']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['tables']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['tables']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['max'],
                'value' => '255',
            ],
            [
                'field_id' => $this->insertKeys['fields']['tables']['sql_name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['max'],
                'value' => '255',
            ],
        ]);
    }

    private function fieldValidationFieldsInserts(): void
    {
        // field validations
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            [
                'field_id' => $this->insertKeys['fields']['fields']['field_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['fields']['field_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::TABLES['fields'].",field_id",
            ],
            [
                'field_id' => $this->insertKeys['fields']['fields']['table_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['fields']['table_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::TABLES['tables'].",table_id",
            ],
            [
                'field_id' => $this->insertKeys['fields']['fields']['field_type_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['fields']['field_type_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::TABLES['field_types'].",field_type_id",
            ],
            [
                'field_id' => $this->insertKeys['fields']['fields']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
        ]);
    }

    private function fieldValidationFieldTypesInserts(): void
    {
        // field validations
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            [
                'field_id' => $this->insertKeys['fields']['field_types']['field_type_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['field_types']['field_type_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::TABLES['field_types'].",field_type_id",
            ],
            [
                'field_id' => $this->insertKeys['fields']['field_types']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['field_types']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
                'value' => null,
            ]
        ]);
    }

    private function fieldValidationValidationTypesInserts(): void
    {
        // field validations
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            [
                'field_id' => $this->insertKeys['fields']['field_validations']['field_validation_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['field_validations']['field_validation_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::TABLES['field_validations'].",field_validation_id",
            ],
            [
                'field_id' => $this->insertKeys['fields']['field_validations']['field_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['field_validations']['field_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::TABLES['fields'].",field_id",
            ],
            [
                'field_id' => $this->insertKeys['fields']['field_validations']['validation_type_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['field_validations']['validation_type_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::TABLES['validation_types'].",validation_type_id",
            ],
            [
                'field_id' => $this->insertKeys['fields']['field_validations']['validation_type_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['max'],
                'value' => '255',
            ],
        ]);
    }

    private function validationTypesInserts(): void
    {
        // validation types
        DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'])->insert([
            [
                'field_id' => $this->insertKeys['fields']['validation_types']['validation_type_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['validation_types']['validation_type_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::TABLES['validation_types'].",validation_type_id",
            ],
            [
                'field_id' => $this->insertKeys['fields']['validation_types']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['validation_types']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
                'value' => null,
            ],
            [
                'field_id' => $this->insertKeys['fields']['validation_types']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['max'],
                'value' => '255',
            ],
        ]);
    }
};
