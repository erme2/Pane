<?php

namespace Tests\Feature\ManagedCredentials;

use App\Models\ManagedCredentialSecret;
use App\Services\ManagedCredentialEncryptionService;
use App\Services\OrganizationTenancyService;
use App\Support\PaneTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManagedCredentialSecretTest extends TestCase
{
    public function test_managed_database_credentials_are_stored_as_separate_encrypted_secret_records(): void
    {
        config()->set('services.managed_credentials.active_key_id', 'key-v1');
        config()->set('services.managed_credentials.keys', json_encode([
            'key-v1' => 'base64:'.base64_encode(str_repeat('c', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)),
        ], JSON_THROW_ON_ERROR));

        $organization = app(OrganizationTenancyService::class)->createOrganization(
            'Credential Workspace',
            'credential-workspace '.Str::uuid()
        );
        $connectionId = (string) Str::uuid();
        $credentials = [
            'host' => 'db.internal',
            'username' => 'managed_app',
            'password' => 'stored-secret-password',
        ];
        $context = [
            'organization_id' => $organization->organization_id,
            'connection_id' => $connectionId,
        ];
        $envelope = app(ManagedCredentialEncryptionService::class)->encrypt($credentials, $context);

        $secret = ManagedCredentialSecret::query()->create([
            'organization_id' => $organization->organization_id,
            'connection_id' => $connectionId,
            'purpose' => ManagedCredentialSecret::PURPOSE_DATABASE_CREDENTIALS,
            'status' => ManagedCredentialSecret::STATUS_ACTIVE,
            'envelope' => $envelope,
        ]);

        $rawEnvelope = DB::table(PaneTable::name(PaneTable::MANAGED_CREDENTIAL_SECRETS))
            ->where('managed_credential_secret_id', $secret->managed_credential_secret_id)
            ->value('envelope');

        $this->assertIsString($rawEnvelope);
        $this->assertStringNotContainsString('stored-secret-password', $rawEnvelope);
        $this->assertStringContainsString('"key_id":"key-v1"', $rawEnvelope);
        $this->assertSame($organization->organization_id, $secret->organization->organization_id);
        $this->assertSame(
            $credentials,
            app(ManagedCredentialEncryptionService::class)->decrypt($secret->fresh()->envelope, $context)
        );
    }
}
