<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::MANAGED_CONNECTIONS), function (Blueprint $table): void {
            $table->uuid('connection_id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('engine', 16);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('host', 253);
            $table->unsignedInteger('port');
            $table->string('database_name', 64);
            $table->string('username', 128);
            $table->string('tls_mode', 32);
            $table->string('status', 16)->index();
            $table->uuid('managed_credential_secret_id')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_reachable')->nullable();
            $table->boolean('last_test_privileges_valid')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name'], 'managed_connections_org_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::MANAGED_CONNECTIONS));
    }
};
