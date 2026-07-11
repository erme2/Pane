<?php

use App\Helpers\SystemMigrationsHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use SystemMigrationsHelper;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table($this->getUsersTableName(), function (Blueprint $table) {
            $table->string('workos_id')->nullable()->unique()->after('email_verified_at');
            $table->string('workos_organization_id')->nullable()->index()->after('workos_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table($this->getUsersTableName(), function (Blueprint $table) {
            $table->dropUnique(['workos_id']);
            $table->dropIndex(['workos_organization_id']);
            $table->dropColumn(['workos_id', 'workos_organization_id']);
        });
    }
};
