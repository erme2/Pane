<?php

namespace Tests\Unit\Services;

use App\Services\ManagedCredentialEncryptionService;
use DomainException;
use RuntimeException;
use Tests\TestCase;

class ManagedCredentialEncryptionServiceTest extends TestCase
{
    private ManagedCredentialEncryptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ManagedCredentialEncryptionService::class);
        $this->configureKeys();
    }

    public function test_encrypts_and_decrypts_credentials_without_plaintext_in_the_envelope(): void
    {
        $credentials = [
            'username' => 'app_user',
            'password' => 'super-secret-password',
            'database' => 'customer_records',
        ];
        $context = ['organization_id' => 'org_123', 'connection_id' => 'conn_123'];

        $envelope = $this->service->encrypt($credentials, $context);

        $this->assertSame(1, $envelope['version']);
        $this->assertSame('XCHACHA20-POLY1305-IETF', $envelope['algorithm']);
        $this->assertSame('key-v2', $envelope['key_id']);
        $this->assertStringNotContainsString('super-secret-password', json_encode($envelope, JSON_THROW_ON_ERROR));
        $this->assertSame($credentials, $this->service->decrypt($envelope, $context));
        $this->assertSame([
            'version' => 1,
            'algorithm' => 'XCHACHA20-POLY1305-IETF',
            'key_id' => 'key-v2',
            'ciphertext_configured' => true,
        ], $this->service->redactedEnvelope($envelope));
    }

    public function test_detects_ciphertext_tampering(): void
    {
        $envelope = $this->service->encrypt(['password' => 'super-secret-password']);
        $decoded = base64_decode((string) $envelope['ciphertext'], true);
        $this->assertIsString($decoded);

        $decoded[0] = chr(ord($decoded[0]) ^ 1);
        $envelope['ciphertext'] = base64_encode($decoded);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Managed credential envelope authentication failed.');

        $this->service->decrypt($envelope);
    }

    public function test_binds_envelopes_to_associated_context(): void
    {
        $envelope = $this->service->encrypt(
            ['password' => 'super-secret-password'],
            ['organization_id' => 'org_123']
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Managed credential envelope authentication failed.');

        $this->service->decrypt($envelope, ['organization_id' => 'org_456']);
    }

    public function test_decrypts_previous_key_versions_and_rotates_to_the_active_key(): void
    {
        $this->configureKeys('key-v1');
        $envelope = $this->service->encrypt(['password' => 'super-secret-password']);

        $this->configureKeys('key-v2');
        $this->assertTrue($this->service->needsRotation($envelope));

        $rotated = $this->service->rotate($envelope);

        $this->assertSame('key-v2', $rotated['key_id']);
        $this->assertFalse($this->service->needsRotation($rotated));
        $this->assertSame(['password' => 'super-secret-password'], $this->service->decrypt($rotated));
        $this->assertSame('key-v2', $this->service->encrypt(['password' => 'new-secret'])['key_id']);
    }

    public function test_health_check_fails_when_active_key_material_is_unavailable(): void
    {
        config()->set('services.managed_credentials.active_key_id', 'missing-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Managed credential key [missing-key] is not configured.');

        $this->service->assertHealthy();
    }

    private function configureKeys(string $activeKeyId = 'key-v2'): void
    {
        config()->set('services.managed_credentials.active_key_id', $activeKeyId);
        config()->set('services.managed_credentials.keys', json_encode([
            'key-v1' => 'base64:'.base64_encode(str_repeat('a', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)),
            'key-v2' => 'base64:'.base64_encode(str_repeat('b', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)),
        ], JSON_THROW_ON_ERROR));
    }
}
