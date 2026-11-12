<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::MESSAGES), function (Blueprint $table): void {
            $table->uuid('message_id')->primary();
            $table->string('type', 64);
            $table->string('purpose', 64)->nullable();
            $table->string('channel', 32);
            $table->string('recipient', 320);
            $table->string('subject', 255);
            // Encrypted with APP_KEY: it can hold credentials such as invitation links.
            $table->text('payload');
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->uuid('organization_id')->nullable()->index();
            $table->string('source_type', 64)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at'], 'messages_status_available_at_index');
            $table->index(['source_type', 'source_id'], 'messages_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::MESSAGES));
    }
};
