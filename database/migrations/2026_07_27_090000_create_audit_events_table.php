<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::AUDIT_EVENTS), function (Blueprint $table) {
            $table->uuid('audit_event_id')->primary();
            $table->dateTimeTz('occurred_at')->index();
            $table->unsignedInteger('real_actor_user_id')->nullable()->index();
            $table->unsignedInteger('effective_user_id')->nullable()->index();
            $table->uuid('organization_id')->nullable()->index();
            $table->string('action')->index();
            $table->string('outcome')->index();
            $table->json('resource_ids')->nullable();
            $table->string('request_id')->nullable()->index();
            $table->json('client_metadata')->nullable();
            $table->uuid('impersonation_session_id')->nullable()->index();
            $table->uuid('connection_id')->nullable()->index();
            $table->string('table_name')->nullable();
            $table->string('row_key')->nullable();
            $table->json('changed_columns')->nullable();
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::AUDIT_EVENTS));
    }
};
