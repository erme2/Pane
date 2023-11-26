<?php

use App\Mappers\AbstractMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(AbstractMapper::MAP_TABLES_PREFIX.'test_table', function (Blueprint $table) {
            $table->unsignedInteger('table_id');
            $table->string('name');
            $table->text('description');
            $table->boolean('is_active');
            $table->timestamp('created_at');
            $table->json('test_array');
            $table->string('password');
            $table->string('email');
            $table->json('test_json');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(AbstractMapper::MAP_TABLES_PREFIX.'test_table');
    }
};
