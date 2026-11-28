<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The personal-data rule of a table map (sweegly#49): which column holds the
 * person and what happens to their rows when the account is anonymised. Null
 * means the table holds no personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(PaneTable::name(PaneTable::MAPS_TABLES), function (Blueprint $table): void {
            $table->json('personal_data')->nullable()->after('queries');
        });
    }

    public function down(): void
    {
        Schema::table(PaneTable::name(PaneTable::MAPS_TABLES), function (Blueprint $table): void {
            $table->dropColumn('personal_data');
        });
    }
};
