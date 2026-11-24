<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::PUSH_SUBSCRIPTIONS), function (Blueprint $table) {
            $table->uuid('subscription_id')->primary();
            $table->unsignedInteger('user_id')->index();
            // A plain string, not a DB enum: native iOS and Android push add kinds without a migration.
            $table->string('kind', 16)->default('web');
            // The push service address. Unique by hash, because a long text column can't carry the index.
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            // Encrypted by the model: these keys let anyone who has them read what is sent to the device.
            $table->text('p256dh');
            $table->text('auth');
            $table->string('device_label', 100)->nullable();
            $table->dateTimeTz('expires_at')->nullable();
            $table->dateTimeTz('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::PUSH_SUBSCRIPTIONS));
    }
};
