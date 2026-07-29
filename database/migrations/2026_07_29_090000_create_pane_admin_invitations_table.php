<?php

use App\Models\PaneAdminInvitation;
use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::PANE_ADMIN_INVITATIONS), function (Blueprint $table) {
            $table->uuid('pane_admin_invitation_id')->primary();
            $table->string('email')->index();
            $table->string('token_hash')->unique();
            $table->string('status')->default(PaneAdminInvitation::STATUS_PENDING)->index();
            $table->unsignedInteger('invited_by_user_id')->index();
            $table->unsignedInteger('accepted_by_user_id')->nullable()->index();
            $table->dateTimeTz('expires_at')->index();
            $table->dateTimeTz('accepted_at')->nullable();
            $table->dateTimeTz('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::PANE_ADMIN_INVITATIONS));
    }
};
