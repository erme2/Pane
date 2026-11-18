<?php

use App\Helpers\SystemMigrationsHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    use SystemMigrationsHelper;

    /**
     * Give every user a stable UUID, used as the owner/member value in CRUD rows.
     */
    public function up(): void
    {
        Schema::table($this->getUsersTableName(), function (Blueprint $table) {
            $table->char('uuid', 36)->nullable()->unique()->after('user_id');
        });

        DB::table($this->getUsersTableName())->whereNull('uuid')
            ->eachById(function (object $user): void {
                DB::table($this->getUsersTableName())
                    ->where('user_id', $user->user_id)
                    ->update(['uuid' => (string) Str::uuid()]);
            }, 100, 'user_id');
    }

    public function down(): void
    {
        Schema::table($this->getUsersTableName(), function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
