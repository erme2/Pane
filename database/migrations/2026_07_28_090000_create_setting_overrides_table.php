<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::SETTING_OVERRIDES), function (Blueprint $table) {
            $table->uuid('setting_override_id')->primary();
            $table->string('setting_key')->index();
            $table->string('scope')->index();
            $table->string('scope_id')->default('')->index();
            $table->json('value');
            $table->unsignedInteger('default_version');
            $table->unsignedInteger('updated_by_user_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['setting_key', 'scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::SETTING_OVERRIDES));
    }
};
