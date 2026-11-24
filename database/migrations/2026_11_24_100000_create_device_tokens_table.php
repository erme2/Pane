<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::DEVICE_TOKENS), function (Blueprint $table) {
            $table->uuid('device_id')->primary();
            $table->unsignedInteger('user_id')->index();
            $table->string('device_label', 100)->nullable();
            // Only hashes are stored: a copy of this table can't sign anyone in.
            $table->char('access_token_hash', 64)->unique();
            $table->dateTimeTz('access_expires_at');
            $table->char('refresh_token_hash', 64)->unique();
            // The refresh token this one replaced: presenting it again means a copy was stolen.
            $table->char('previous_refresh_hash', 64)->nullable()->index();
            $table->dateTimeTz('refresh_expires_at');
            // The WorkOS session id (not a credential), so signing out can end the WorkOS session too.
            $table->string('workos_session_id', 255)->nullable();
            $table->dateTimeTz('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::DEVICE_TOKENS));
    }
};
