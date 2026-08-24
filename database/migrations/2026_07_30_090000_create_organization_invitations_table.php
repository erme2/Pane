<?php

use App\Models\OrganizationInvitation;
use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::ORGANIZATION_INVITATIONS), function (Blueprint $table) {
            $table->uuid('organization_invitation_id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('email')->index();
            $table->string('token_hash')->unique();
            $table->string('role')->index();
            $table->string('status')->default(OrganizationInvitation::STATUS_PENDING)->index();
            $table->unsignedInteger('invited_by_user_id')->index();
            $table->unsignedInteger('accepted_by_user_id')->nullable()->index();
            $table->dateTimeTz('expires_at')->index();
            $table->dateTimeTz('accepted_at')->nullable();
            $table->dateTimeTz('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email', 'status'], 'org_invites_org_email_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::ORGANIZATION_INVITATIONS));
    }
};
