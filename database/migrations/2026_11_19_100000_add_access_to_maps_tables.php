<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The access rule of a table map (Pane#185): which members table decides who may
 * read and write the rows of the table through the CRUD route. Null means the
 * table has no members rule (administrators only for writes, as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(PaneTable::name(PaneTable::MAPS_TABLES), function (Blueprint $table): void {
            $table->json('access')->nullable()->after('ui');
        });
    }

    public function down(): void
    {
        Schema::table(PaneTable::name(PaneTable::MAPS_TABLES), function (Blueprint $table): void {
            $table->dropColumn('access');
        });
    }
};
