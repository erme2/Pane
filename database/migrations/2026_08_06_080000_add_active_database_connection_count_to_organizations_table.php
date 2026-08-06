<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(PaneTable::name(PaneTable::ORGANIZATIONS), function (Blueprint $table): void {
            $table->unsignedInteger('active_database_connections')->default(0)->after('database_limit');
        });
    }

    public function down(): void
    {
        Schema::table(PaneTable::name(PaneTable::ORGANIZATIONS), function (Blueprint $table): void {
            $table->dropColumn('active_database_connections');
        });
    }
};
