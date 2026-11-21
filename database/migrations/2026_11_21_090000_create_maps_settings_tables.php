<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App settings (Pane#158): `maps_settings` holds the typed declarations (one
 * version for the whole list, kept in maps_versions as the subject `settings`)
 * and `maps_setting_values` the value each key has in this install. System
 * tables: the interface never edits them directly. `value` is JSON so a text
 * can later hold one string per language without a migration (decision 26).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::MAPS_SETTINGS), function (Blueprint $table): void {
            $table->unsignedBigInteger('map_setting_id')->autoIncrement();
            $table->string('key', 64)->unique();
            // flag, text, number or image.
            $table->string('type', 16);
            $table->json('default')->nullable();
            $table->boolean('public')->default(false);
            // administrator or pane_admin.
            $table->string('editable_by', 16);
            $table->string('label', 255)->nullable();
            $table->string('help', 1000)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create(PaneTable::name(PaneTable::MAPS_SETTING_VALUES), function (Blueprint $table): void {
            $table->unsignedBigInteger('map_setting_value_id')->autoIncrement();
            $table->string('key', 64)->unique();
            $table->json('value')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('updated_by', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::MAPS_SETTING_VALUES));
        Schema::dropIfExists(PaneTable::name(PaneTable::MAPS_SETTINGS));
    }
};
