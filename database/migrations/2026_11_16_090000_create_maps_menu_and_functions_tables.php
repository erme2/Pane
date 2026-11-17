<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The menu and the switchable functions Latte builds its shell from (Pane#178,
 * contract Pane#169). System tables like the other maps tables: the interface
 * never edits them directly. Seeds one menu item and one function per known
 * Latte function. Self-contained on purpose, so later code changes can't break
 * this migration.
 */
return new class extends Migration
{
    /** Function key => enabled by default. */
    private const array FUNCTIONS = ['records' => true, 'members' => true, 'dashboard' => false];

    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::MAPS_FUNCTIONS), function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create(PaneTable::name(PaneTable::MAPS_MENU), function (Blueprint $table): void {
            $table->unsignedBigInteger('map_menu_id')->autoIncrement();
            $table->string('key', 64)->unique();
            $table->string('label', 255);
            $table->string('icon', 64)->nullable();
            // `table` (target_source + target_table) or `function` (target_function).
            $table->string('target_type', 16);
            $table->string('target_source', 64)->nullable();
            $table->string('target_table', 64)->nullable();
            $table->string('target_function', 64)->nullable();
            // Key of the parent item; one level of nesting only.
            $table->string('parent_key', 64)->nullable();
            $table->unsignedInteger('position')->default(0);
            // Every save of the menu sets the same version on all rows (written by Pane#157).
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        $now = now();
        $position = 0;
        foreach (self::FUNCTIONS as $key => $enabled) {
            DB::table(PaneTable::name(PaneTable::MAPS_FUNCTIONS))->insert([
                'key' => $key, 'enabled' => $enabled, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table(PaneTable::name(PaneTable::MAPS_MENU))->insert([
                'key' => $key, 'label' => ucfirst($key), 'icon' => null,
                'target_type' => 'function', 'target_function' => $key,
                'position' => $position++, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::MAPS_MENU));
        Schema::dropIfExists(PaneTable::name(PaneTable::MAPS_FUNCTIONS));
    }
};
