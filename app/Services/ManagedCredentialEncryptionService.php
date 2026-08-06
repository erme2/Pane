<?php

namespace App\Services;

use DomainException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SodiumException;

class ManagedCredentialEncryptionService
{
    private const int ENVELOPE_VERSION = 1;

    private const string ALGORITHM = 'XCHACHA20-POLY1305-IETF';

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function encrypt(array $credentials, array $context = []): array
    {
        $this->assertSodiumAvailable();
        $plaintext = $this->encodeJson($credentials);
        $keyId = $this->activeKeyId();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $this->associatedData($context),
                $nonce,
                $this->keyFor($keyId)
            );
        } catch (SodiumException $exception) {
            throw new RuntimeException('Managed credential encryption failed.', previous: $exception);
        } finally {
            sodium_memzero($plaintext);
        }

        return [
            'version' => self::ENVELOPE_VERSION,
            'algorithm' => self::ALGORITHM,
            'key_id' => $keyId,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function decrypt(array $envelope, array $context = []): array
    {
        $this->assertSodiumAvailable();
        $this->assertEnvelope($envelope);

        $keyId = (string) $envelope['key_id'];
        $nonce = $this->decodeBase64Field((string) $envelope['nonce'], 'nonce');
        $ciphertext = $this->decodeBase64Field((string) $envelope['ciphertext'], 'ciphertext');

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $this->associatedData($context),
                $nonce,
                $this->keyFor($keyId)
            );
        } catch (SodiumException $exception) {
            throw new DomainException('Managed credential envelope authentication failed.', previous: $exception);
        }

        if (! is_string($plaintext)) {
            throw new DomainException('Managed credential envelope authentication failed.');
        }

        try {
            $credentials = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('Managed credential plaintext is not valid JSON.', previous: $exception);
        } finally {
            sodium_memzero($plaintext);
        }

        if (! is_array($credentials)) {
            throw new DomainException('Managed credential plaintext must be an object.');
        }

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function needsRotation(array $envelope): bool
    {
        $this->assertEnvelope($envelope);

        return ! hash_equals($this->activeKeyId(), (string) $envelope['key_id']);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function rotate(array $envelope, array $context = []): array
    {
        if (! $this->needsRotation($envelope)) {
            return $envelope;
        }

        return $this->encrypt($this->decrypt($envelope, $context), $context);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function redactedEnvelope(array $envelope): array
    {
        $this->assertEnvelope($envelope);

        return [
            'version' => $envelope['version'],
            'algorithm' => $envelope['algorithm'],
            'key_id' => $envelope['key_id'],
            'ciphertext_configured' => true,
        ];
    }

    public function assertHealthy(): void
    {
        $this->keyFor($this->activeKeyId());
    }

    private function activeKeyId(): string
    {
        $keyId = config('services.managed_credentials.active_key_id');

        if (! is_string($keyId) || ! $this->validKeyId($keyId)) {
            throw new RuntimeException('PANE_MANAGED_CREDENTIAL_ACTIVE_KEY_ID must be configured with a valid key id.');
        }

        return $keyId;
    }

    private function keyFor(string $keyId): string
    {
        $keys = $this->configuredKeys();
        $encoded = $keys[$keyId] ?? null;

        if (! is_string($encoded)) {
            throw new RuntimeException("Managed credential key [$keyId] is not configured.");
        }

        $key = $this->decodeKey($encoded, $keyId);

        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException("Managed credential key [$keyId] must decode to 32 bytes.");
        }

        return $key;
    }

    /**
     * @return array<string, string>
     */
    private function configuredKeys(): array
    {
        $configured = config('services.managed_credentials.keys');

        if (! is_string($configured) || trim($configured) === '') {
            throw new RuntimeException('PANE_MANAGED_CREDENTIAL_KEYS must be configured as a JSON object.');
        }

        try {
            $keys = json_decode($configured, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('PANE_MANAGED_CREDENTIAL_KEYS must be valid JSON.', previous: $exception);
        }

        if (! is_array($keys) || $keys === []) {
            throw new RuntimeException('PANE_MANAGED_CREDENTIAL_KEYS must contain at least one key.');
        }

        foreach ($keys as $keyId => $key) {
            if (! is_string($keyId) || ! $this->validKeyId($keyId) || ! is_string($key)) {
                throw new RuntimeException('PANE_MANAGED_CREDENTIAL_KEYS must map valid key ids to encoded keys.');
            }
        }

        /** @var array<string, string> $keys */
        return $keys;
    }

    private function decodeKey(string $encoded, string $keyId): string
    {
        if (! str_starts_with($encoded, 'base64:')) {
            throw new RuntimeException("Managed credential key [$keyId] must use the base64: prefix.");
        }

        $decoded = base64_decode(substr($encoded, 7), true);

        if (! is_string($decoded)) {
            throw new RuntimeException("Managed credential key [$keyId] is not valid base64.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function assertEnvelope(array $envelope): void
    {
        if (($envelope['version'] ?? null) !== self::ENVELOPE_VERSION) {
            throw new InvalidArgumentException('Managed credential envelope version is unsupported.');
        }

        if (($envelope['algorithm'] ?? null) !== self::ALGORITHM) {
            throw new InvalidArgumentException('Managed credential envelope algorithm is unsupported.');
        }

        if (! is_string($envelope['key_id'] ?? null) || ! $this->validKeyId($envelope['key_id'])) {
            throw new InvalidArgumentException('Managed credential envelope key id is invalid.');
        }

        if (! is_string($envelope['nonce'] ?? null) || ! is_string($envelope['ciphertext'] ?? null)) {
            throw new InvalidArgumentException('Managed credential envelope nonce and ciphertext are required.');
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function associatedData(array $context): string
    {
        return $this->encodeJson([
            'purpose' => 'pane.managed_database_credentials',
            'version' => self::ENVELOPE_VERSION,
            'context' => $this->stableArray($context),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function stableArray(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->stableArray($nested);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Managed credential data must be JSON serializable.', previous: $exception);
        }
    }

    private function decodeBase64Field(string $encoded, string $field): string
    {
        $decoded = base64_decode($encoded, true);

        if (! is_string($decoded)) {
            throw new InvalidArgumentException("Managed credential envelope $field is not valid base64.");
        }

        return $decoded;
    }

    private function validKeyId(string $keyId): bool
    {
        return preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $keyId) === 1;
    }

    private function assertSodiumAvailable(): void
    {
        if (! defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES')) {
            throw new RuntimeException('The sodium extension is required for managed credential encryption.');
        }
    }
}
