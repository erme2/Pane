<?php

use App\Models\Message;
use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::MESSAGES), function (Blueprint $table) {
            $table->uuid('message_id')->primary();
            // A plain string, not a DB enum: a new channel (sms, push) needs no migration.
            $table->string('channel', 32);
            $table->string('recipient');
            $table->string('subject')->nullable();
            // Encrypted by the model: a body can hold a credential such as an invitation link.
            $table->text('body');
            $table->string('status', 16)->default(Message::STATUS_PENDING);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTimeTz('available_at');
            $table->dateTimeTz('reserved_at')->nullable();
            $table->dateTimeTz('sent_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->string('source_type', 64)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::MESSAGES));
    }
};
