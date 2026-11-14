<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::CATALOG_OBJECTS), function (Blueprint $table): void {
            $table->uuid('catalog_object_id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('connection_id');
            $table->uuid('parent_id')->nullable()->index();
            // sha256 of the kind and the exact (case-sensitive) identifiers, so lookups don't depend on collation.
            $table->char('object_key', 64);
            $table->string('kind', 16);
            $table->unsignedTinyInteger('sort_rank');
            $table->string('name', 200);
            $table->string('status', 16);
            $table->json('structure');
            $table->json('issues');
            $table->text('description')->nullable();
            $table->boolean('crud_enabled')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'object_key'], 'catalog_objects_connection_key_unique');
            $table->index(['connection_id', 'sort_rank', 'name'], 'catalog_objects_connection_order_index');
        });

        Schema::create(PaneTable::name(PaneTable::CATALOG_REFRESHES), function (Blueprint $table): void {
            $table->uuid('catalog_refresh_id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('connection_id')->index();
            $table->string('status', 16);
            $table->json('counts')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::CATALOG_REFRESHES));
        Schema::dropIfExists(PaneTable::name(PaneTable::CATALOG_OBJECTS));
    }
};
