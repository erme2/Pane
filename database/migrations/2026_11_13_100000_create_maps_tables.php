<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The maps (the description layer, Pane#153): sources, tables and fields, plus
 * the version history of every edit. Rows hold the current state; each save
 * also writes a full snapshot to maps_versions. These are system tables: the
 * interface can never edit them (decision 32). Editing a map never changes the
 * table it describes (decision 29).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::MAPS_SOURCES), function (Blueprint $table): void {
            $table->unsignedBigInteger('map_source_id')->autoIncrement();
            $table->string('name', 64)->unique();
            // `install`: the install's own database. `external` is parked (decision 10).
            $table->string('kind', 16);
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        Schema::create(PaneTable::name(PaneTable::MAPS_TABLES), function (Blueprint $table): void {
            $table->unsignedBigInteger('map_table_id')->autoIncrement();
            $table->unsignedBigInteger('map_source_id')->index();
            $table->string('name', 64);
            $table->string('sql_name', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('ui')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['map_source_id', 'name'], 'maps_tables_source_name_unique');
        });

        Schema::create(PaneTable::name(PaneTable::MAPS_FIELDS), function (Blueprint $table): void {
            $table->unsignedBigInteger('map_field_id')->autoIncrement();
            $table->unsignedBigInteger('map_table_id')->index();
            $table->string('name', 64);
            $table->string('sql_name', 255)->nullable();
            $table->string('type', 32);
            $table->text('description')->nullable();
            $table->string('format', 255)->nullable();
            $table->boolean('primary')->default(false);
            $table->boolean('index')->default(false);
            $table->boolean('sortable')->default(false);
            $table->boolean('nullable')->default(false);
            $table->json('default')->nullable();
            $table->json('validations')->nullable();
            // Stored only for now; evaluated by the read and CRUD tickets (#154, #156).
            $table->json('computed')->nullable();
            $table->json('ui')->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->unique(['map_table_id', 'name'], 'maps_fields_table_name_unique');
        });

        Schema::create(PaneTable::name(PaneTable::MAPS_VERSIONS), function (Blueprint $table): void {
            $table->unsignedBigInteger('map_version_id')->autoIncrement();
            // `source` or `table`; subject_id is map_source_id or map_table_id.
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->json('diff')->nullable();
            $table->string('author', 255);
            $table->unsignedInteger('restored_from')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['subject_type', 'subject_id', 'version'], 'maps_versions_subject_version_unique');
        });
    }

    public function down(): void
    {
        foreach (array_reverse(PaneTable::MAPS) as $table) {
            Schema::dropIfExists(PaneTable::name($table));
        }
    }
};
