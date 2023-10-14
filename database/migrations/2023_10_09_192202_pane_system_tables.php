<?php

use App\Mappers\AbstractMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $fieldIDs = [];


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
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['validation_types']['name']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['field_validations']['name']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['field_types']['name']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['fields']['name']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['tables']['name']);
    }
    private function buildMainStructure(): void
    {
        Schema::create(AbstractMapper::MAPPING_TABLES['tables']['name'], function (Blueprint $table) {
            $table->unsignedInteger('table_id')->autoIncrement();
            $table->string('name', 255)->index();
            $table->string('sql_name', 255)->nullable();
            $table->text('description')->nullable();
        });
        Schema::create(AbstractMapper::MAPPING_TABLES['fields']['name'], function (Blueprint $table) {
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
        Schema::create(AbstractMapper::MAPPING_TABLES['field_types']['name'], function (Blueprint $table) {
            $table->unsignedSmallInteger('field_type_id')->autoIncrement();
            $table->string('name', 255);
        });
        Schema::create(AbstractMapper::MAPPING_TABLES['field_validations']['name'], function (Blueprint $table) {
            $table->unsignedInteger('field_validation_id')->autoIncrement();
            $table->unsignedBigInteger('field_id')->index();
            $table->unsignedSmallInteger('validation_type_id');
            $table->string('value', 255)->nullable();
        });
        Schema::create(AbstractMapper::MAPPING_TABLES['validation_types']['name'], function (Blueprint $table) {
            $table->unsignedInteger('validation_type_id')->autoIncrement();
            $table->string('name', 255);
        });
    }
    private function tablesInserts(): void
    {
        // tables
        DB::table(AbstractMapper::MAPPING_TABLES['tables']['name'])->insert([
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['tables']['id'],
                'name' => AbstractMapper::MAPPING_TABLES['tables']['name'],
                'sql_name' => AbstractMapper::MAPPING_TABLES['tables']['name'],
                'description' => "pane system table for storing tables",
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'name' => AbstractMapper::MAPPING_TABLES['fields']['name'],
                'sql_name' => AbstractMapper::MAPPING_TABLES['fields']['name'],
                'description' => "pane system table for storing fields",
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['field_types']['id'],
                'name' => AbstractMapper::MAPPING_TABLES['field_types']['name'],
                'sql_name' => AbstractMapper::MAPPING_TABLES['field_types']['name'],
                'description' => "pane system table for storing field types",
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validations']['id'],
                'name' => AbstractMapper::MAPPING_TABLES['field_validations']['name'],
                'sql_name' => AbstractMapper::MAPPING_TABLES['field_validations']['name'],
                'description' => "pane system table for storing field validations rules",
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['validation_types']['id'],
                'name' => AbstractMapper::MAPPING_TABLES['validation_types']['name'],
                'sql_name' => AbstractMapper::MAPPING_TABLES['validation_types']['name'],
                'description' => "pane system table for storing validations rule types",
            ],
        ]);
    }
    private function fieldTypesInserts(): void
    {
        // fields types
        DB::table(AbstractMapper::MAPPING_TABLES['field_types']['name'])->insert([
            [
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'integer',
            ],
            [
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'string',
            ],
            [
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['text'],
                'name' => 'text',
            ],
            [
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['boolean'],
                'name' => 'boolean',
            ],
            [
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['timestamp'],
                'name' => 'timestamp',
            ],
            [
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['array'],
                'name' => 'array',
            ],
            [
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['password'] ,
                'name' => 'password',
            ],
        ]);
    }
    private function fieldsInsertsTables(): void
    {
        // FIELDS
        $this->fieldIDs['tables']['table_id'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['tables']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'table_id',
                'primary' => true,
                'index' => true,
                'nullable' => false,
            ]);
        $this->fieldIDs['tables']['name'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['tables']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'name',
                'primary' => false,
                'index' => true,
                'nullable' => false,
            ]);
        $this->fieldIDs['tables']['sql_name'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['tables']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'sql_name',
                'primary' => false,
                'index' => false,
                'nullable' => true,
            ]);
        $this->fieldIDs['tables']['description'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['tables']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['text'],
                'name' => 'description',
                'primary' => false,
                'index' => false,
                'nullable' => true,
            ]);
    }
    private function fieldsInsertsFields(): void
    {
        // fields
        $this->fieldIDs['fields']['field_id'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_id',
                'primary' => true,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->fieldIDs['fields']['table_id'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'table_id',
                'primary' => false,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->fieldIDs['fields']['field_type_id'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_type_id',
                'primary' => false,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->fieldIDs['fields']['name'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'name',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
            ]);
        $this->fieldIDs['fields']['sql_name'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'sql_name',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
        $this->fieldIDs['fields']['description'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['text'],
                'name' => 'description',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
        $this->fieldIDs['fields']['primary'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['boolean'],
                'name' => 'primary',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => 'false',
            ]);
        $this->fieldIDs['fields']['index'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['boolean'],
                'name' => 'index',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => 'false',
            ]);
        $this->fieldIDs['fields']['nullable'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['boolean'],
                'name' => 'nullable',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => 'false',
            ]);
        $this->fieldIDs['fields']['default'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
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
        $this->fieldIDs['field_types']['field_type_id'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['field_types']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_type_id',
                'primary' => true,
                'index' => true,
            ]);
        $this->fieldIDs['field_types']['name'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['field_types']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'name',
                'primary' => false,
                'index' => false,
            ]);
    }
    private function fieldsInsertsFieldValidations(): void
    {
        // field validations
        $this->fieldIDs['field_validations']['field_validation_id'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validations']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_validation_id',
                'primary' => true,
                'index' => true,
                'nullable' => false,
            ]);
        $this->fieldIDs['field_validations']['field_id'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validations']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_id',
                'primary' => false,
                'index' => true,
                'nullable' => false,
            ]);
        $this->fieldIDs['field_validations']['validation_type_id'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validations']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'validation_type_id',
                'primary' => false,
                'index' => false,
                'nullable' => false,
            ]);
        $this->fieldIDs['field_validations']['value'] =
            DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validations']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'value',
                'primary' => false,
                'index' => false,
                'nullable' => true,
            ]);
    }
    private function fieldsInsertsValidationRules(): void
    {
        // validation types
        $this->fieldIDs['validation_types']['validation_type_id'] =
        DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['validation_types']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'validation_type_id',
                'primary' => true,
                'index' => true,
            ]);
        $this->fieldIDs['validation_types']['name'] =
        DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insertGetId([
                'table_id' => AbstractMapper::MAPPING_TABLES['validation_types']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'name',
                'primary' => false,
                'index' => false,
            ]);
    }
    private function fieldValidationTypesInserts(): void
    {
        DB::table(AbstractMapper::MAPPING_TABLES['validation_types']['name'])->insert([
            [
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'name' => 'required',
            ],
            [
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['unique'],
                'name' => 'unique',
            ],
            [
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['exists'],
                'name' => 'exists',
            ],
            [
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['min'],
                'name' => 'min',
            ],
            [
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['max'],
                'name' => 'max',
            ],
        ]);
    }
    private function fieldValidationTablesInserts(): void
    {
        // field validations
        DB::table(AbstractMapper::MAPPING_TABLES['field_validations']['name'])->insert([
            [
                'field_id' => $this->fieldIDs['tables']['table_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['tables']['table_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::MAPPING_TABLES['tables']['name'].",table_id",
            ],
            [
                'field_id' => $this->fieldIDs['tables']['name'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['tables']['name'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['unique'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['tables']['name'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['max'],
                'value' => '255',
            ],
            [
                'field_id' => $this->fieldIDs['tables']['sql_name'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['max'],
                'value' => '255',
            ],
        ]);
    }
    private function fieldValidationFieldsInserts(): void
    {
        // field validations
        DB::table(AbstractMapper::MAPPING_TABLES['field_validations']['name'])->insert([
            [
                'field_id' => $this->fieldIDs['fields']['field_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['fields']['field_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::MAPPING_TABLES['fields']['name'].",field_id",
            ],
            [
                'field_id' => $this->fieldIDs['fields']['table_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['fields']['table_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::MAPPING_TABLES['tables']['name'].",table_id",
            ],
            [
                'field_id' => $this->fieldIDs['fields']['field_type_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['fields']['field_type_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::MAPPING_TABLES['field_types']['name'].",field_type_id",
            ],
            [
                'field_id' => $this->fieldIDs['fields']['name'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
        ]);
    }
    private function fieldValidationFieldTypesInserts(): void
    {
        // field validations
        DB::table(AbstractMapper::MAPPING_TABLES['field_validations']['name'])->insert([
            [
                'field_id' => $this->fieldIDs['field_types']['field_type_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['field_types']['field_type_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::MAPPING_TABLES['field_types']['name'].",field_type_id",
            ],
            [
                'field_id' => $this->fieldIDs['field_types']['name'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['field_types']['name'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['unique'],
                'value' => null,
            ]
        ]);
    }
    private function fieldValidationValidationTypesInserts(): void
    {
        // field validations
        DB::table(AbstractMapper::MAPPING_TABLES['field_validations']['name'])->insert([
            [
                'field_id' => $this->fieldIDs['field_validations']['field_validation_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['field_validations']['field_validation_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::MAPPING_TABLES['field_validations']['name'].",field_validation_id",
            ],
            [
                'field_id' => $this->fieldIDs['field_validations']['field_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['field_validations']['field_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::MAPPING_TABLES['fields']['name'].",field_id",
            ],
            [
                'field_id' => $this->fieldIDs['field_validations']['validation_type_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['field_validations']['validation_type_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::MAPPING_TABLES['validation_types']['name'].",validation_type_id",
            ],
            [
                'field_id' => $this->fieldIDs['field_validations']['validation_type_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['max'],
                'value' => '255',
            ],
        ]);
    }
    private function validationTypesInserts(): void
    {
        // validation types
        DB::table(AbstractMapper::MAPPING_TABLES['field_validations']['name'])->insert([
            [
                'field_id' => $this->fieldIDs['validation_types']['validation_type_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['validation_types']['validation_type_id'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['exists'],
                'value' => AbstractMapper::MAPPING_TABLES['validation_types']['name'].",validation_type_id",
            ],
            [
                'field_id' => $this->fieldIDs['validation_types']['name'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['required'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['validation_types']['name'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['unique'],
                'value' => null,
            ],
            [
                'field_id' => $this->fieldIDs['validation_types']['name'],
                'validation_type_id' => AbstractMapper::MAPPING_VALIDATION_TYPES['max'],
                'value' => '255',
            ],
        ]);
    }
};
