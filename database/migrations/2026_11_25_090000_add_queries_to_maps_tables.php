<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The named queries of a table map (Pane#225): filters, sort and join an admin
 * describes once, with typed parameters, and clients call by name. Null means
 * the table has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(PaneTable::name(PaneTable::MAPS_TABLES), function (Blueprint $table): void {
            $table->json('queries')->nullable()->after('access');
        });
    }

    public function down(): void
    {
        Schema::table(PaneTable::name(PaneTable::MAPS_TABLES), function (Blueprint $table): void {
            $table->dropColumn('queries');
        });
    }
};
