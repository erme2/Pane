<?php

use App\Mappers\AbstractMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{



    /**
     * Run the migrations.
     */
    public function up(): void
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
            $table->boolean('is_index')->default(false);
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
                $table->unsignedSmallInteger('field_validation_type_id');
                $table->string('value', 255)->nullable();
            });
        Schema::create(AbstractMapper::MAPPING_TABLES['field_validation_types']['name'], function (Blueprint $table) {
                $table->unsignedInteger('field_validation_type_id')->autoIncrement();
                $table->string('name', 255);
            });

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
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validation_types']['id'],
                'name' => AbstractMapper::MAPPING_TABLES['field_validation_types']['name'],
                'sql_name' => AbstractMapper::MAPPING_TABLES['field_validation_types']['name'],
                'description' => "pane system table for storing field validations rules types",
            ],
        ]);
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
        // table fields
        DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insert([
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['tables']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'table_id',
                'is_index' => true,
                'nullable' => false,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['tables']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'name',
                'is_index' => true,
                'nullable' => false,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['tables']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'sql_name',
                'is_index' => false,
                'nullable' => true,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['tables']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['text'],
                'name' => 'description',
                'is_index' => false,
                'nullable' => true,
            ],

        ]);
        // fields (table) fields
        DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insert([
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_id',
                'is_index' => true,
                'nullable' => false,
                'default' => null,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'table_id',
                'is_index' => true,
                'nullable' => false,
                'default' => null,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_type_id',
                'is_index' => true,
                'nullable' => false,
                'default' => null,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'name',
                'is_index' => false,
                'nullable' => false,
                'default' => null,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'sql_name',
                'is_index' => false,
                'nullable' => true,
                'default' => null,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['text'],
                'name' => 'description',
                'is_index' => false,
                'nullable' => true,
                'default' => null,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['boolean'],
                'name' => 'is_index',
                'is_index' => false,
                'nullable' => false,
                'default' => 'false',
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['boolean'],
                'name' => 'nullable',
                'is_index' => false,
                'nullable' => false,
                'default' => 'false',
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['fields']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'default',
                'is_index' => false,
                'nullable' => true,
                'default' => null,
            ]
        ]);
        // field types fields
        DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insert([
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['field_types']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_type_id',
                'is_index' => true,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['field_types']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'name',
                'is_index' => false,
            ],
        ]);
        // field validations fields
        DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insert([
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validations']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_validation_id',
                'is_index' => true,
                'nullable' => false,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validations']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_id',
                'is_index' => true,
                'nullable' => false,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validations']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_validation_type_id',
                'is_index' => false,
                'nullable' => false,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validations']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'value',
                'is_index' => false,
                'nullable' => true,
            ],
        ]);
        // field validation types fields
        DB::table(AbstractMapper::MAPPING_TABLES['fields']['name'])->insert([
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validation_types']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['integer'],
                'name' => 'field_validation_type_id',
                'is_index' => true,
            ],
            [
                'table_id' => AbstractMapper::MAPPING_TABLES['field_validation_types']['id'],
                'field_type_id' => AbstractMapper::MAPPING_FIELD_TYPES['string'],
                'name' => 'name',
                'is_index' => false,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['field_validation_types']['name']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['field_validations']['name']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['field_types']['name']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['fields']['name']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['tables']['name']);
    }
};
