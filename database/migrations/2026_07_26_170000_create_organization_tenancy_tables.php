<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::ORGANIZATIONS), function (Blueprint $table) {
            $table->uuid('organization_id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('database_limit')->default(1);
            $table->json('details')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create(PaneTable::name(PaneTable::ORGANIZATION_MEMBERSHIPS), function (Blueprint $table) {
            $table->uuid('membership_id')->primary();
            $table->uuid('organization_id')->index();
            $table->unsignedInteger('user_id')->index();
            $table->string('role')->index();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('invited_by_user_id')->nullable()->index();
            $table->dateTimeTz('accepted_at')->nullable();
            $table->dateTimeTz('suspended_at')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id'], 'org_members_org_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::ORGANIZATION_MEMBERSHIPS));
        Schema::dropIfExists(PaneTable::name(PaneTable::ORGANIZATIONS));
    }
};
