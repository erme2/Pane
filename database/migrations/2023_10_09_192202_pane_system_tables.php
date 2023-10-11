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
        Schema::create(AbstractMapper::MAPPING_TABLES['tables'], function (Blueprint $table) {
            $table->unsignedInteger('table_id')->autoIncrement();
            $table->string('name', 255)->index();
            $table->string('sql_name', 255)->nullable();
            $table->text('description')->nullable();
        });
        Schema::create(AbstractMapper::MAPPING_TABLES['fields'], function (Blueprint $table) {
            $table->unsignedBigInteger('field_id')->autoIncrement();
            $table->unsignedInteger('table_id')->index();
            $table->unsignedSmallInteger('field_type_id')->index();
            $table->string('name', 255);
            $table->string('sql_name', 255)->nullable();
            $table->text('description')->nullable();
        });
        Schema::create(AbstractMapper::MAPPING_TABLES['field_types'], function (Blueprint $table) {
            $table->unsignedSmallInteger('field_type_id')->autoIncrement();
            $table->string('name', 255)->index();
        });
        Schema::create(AbstractMapper::MAPPING_TABLES['field_validations'], function (Blueprint $table) {
                $table->unsignedInteger('field_validation_id')->autoIncrement();
                $table->unsignedBigInteger('field_id')->index();
                $table->unsignedSmallInteger('type_id');
                $table->string('value', 255);
            });
        Schema::create(AbstractMapper::MAPPING_TABLES['field_validation_types'], function (Blueprint $table) {
                $table->unsignedInteger('field_validation_type_id')->autoIncrement();
                $table->string('name', 255);
            });

        DB::table(AbstractMapper::MAPPING_TABLES['tables'])->insert([
            [
                'table_id' => 1,
                'name' => AbstractMapper::MAPPING_TABLES['tables'],
                'sql_name' => AbstractMapper::MAPPING_TABLES['tables'],
                'description' => "pane system table for storing tables",
            ],
            [
                'table_id' => 2,
                'name' => AbstractMapper::MAPPING_TABLES['fields'],
                'sql_name' => AbstractMapper::MAPPING_TABLES['fields'],
                'description' => "pane system table for storing fields",
            ],
            [
                'table_id' => 3,
                'name' => AbstractMapper::MAPPING_TABLES['field_types'],
                'sql_name' => AbstractMapper::MAPPING_TABLES['field_types'],
                'description' => "pane system table for storing field types",
            ],
            [
                'table_id' => 4,
                'name' => AbstractMapper::MAPPING_TABLES['field_validations'],
                'sql_name' => AbstractMapper::MAPPING_TABLES['field_validations'],
                'description' => "pane system table for storing field validations rules",
            ],
            [
                'table_id' => 5,
                'name' => AbstractMapper::MAPPING_TABLES['field_validation_types'],
                'sql_name' => AbstractMapper::MAPPING_TABLES['field_validation_types'],
                'description' => "pane system table for storing field validations rules types",
            ],
        ]);

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['field_validation_types']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['field_validations']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['field_types']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['fields']);
        Schema::dropIfExists(AbstractMapper::MAPPING_TABLES['tables']);
    }
};
