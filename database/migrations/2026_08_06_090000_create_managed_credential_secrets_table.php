<?php

use App\Models\ManagedCredentialSecret;
use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::MANAGED_CREDENTIAL_SECRETS), function (Blueprint $table): void {
            $table->uuid('managed_credential_secret_id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('connection_id')->nullable()->index();
            $table->string('purpose')->default(ManagedCredentialSecret::PURPOSE_DATABASE_CREDENTIALS)->index();
            $table->string('status')->default(ManagedCredentialSecret::STATUS_ACTIVE)->index();
            $table->json('envelope');
            $table->timestamps();

            $table->index(['organization_id', 'purpose', 'status'], 'managed_secrets_org_purpose_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::MANAGED_CREDENTIAL_SECRETS));
    }
};
