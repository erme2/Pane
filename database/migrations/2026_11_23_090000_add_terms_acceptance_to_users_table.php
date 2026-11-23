<?php

use App\Helpers\SystemMigrationsHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use SystemMigrationsHelper;

    /**
     * The latest Terms and Privacy version a user accepted (Pane#217). Only the latest
     * acceptance is kept, with no history table.
     */
    public function up(): void
    {
        Schema::table($this->getUsersTableName(), function (Blueprint $table) {
            $table->string('terms_version', 100)->nullable();
            $table->dateTimeTz('terms_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->getUsersTableName(), function (Blueprint $table) {
            $table->dropColumn(['terms_version', 'terms_accepted_at']);
        });
    }
};
