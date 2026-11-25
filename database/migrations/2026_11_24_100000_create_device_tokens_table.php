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
            $table->dateTimeTz('refresh_expires_at');
            // The WorkOS session id (not a credential), so signing out can end the WorkOS session too.
            $table->string('workos_session_id', 255)->nullable();
            $table->dateTimeTz('last_used_at')->nullable();
            $table->timestamps();
        });

        // Every refresh token a device has used up, until it would have expired anyway:
        // presenting any of them again, however many rotations ago, means a copy was stolen.
        Schema::create(PaneTable::name(PaneTable::DEVICE_CONSUMED_REFRESH_TOKENS), function (Blueprint $table) {
            $table->char('refresh_token_hash', 64)->primary();
            $table->uuid('device_id')->index();
            $table->dateTimeTz('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::DEVICE_CONSUMED_REFRESH_TOKENS));
        Schema::dropIfExists(PaneTable::name(PaneTable::DEVICE_TOKENS));
    }
};
