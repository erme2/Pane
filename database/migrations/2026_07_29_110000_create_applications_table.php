<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::APPLICATIONS), function (Blueprint $table) {
            $table->uuid('application_id')->primary();
            $table->uuid('organization_id')->nullable()->index();
            $table->string('name', 200);
            $table->string('kind')->index();
            $table->string('trusted_origin');
            $table->string('active_trusted_origin')->nullable()->unique();
            $table->uuid('session_version')->index();
            $table->json('redirect_uris');
            $table->string('status')->default('active')->index();
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::APPLICATIONS));
    }
};
