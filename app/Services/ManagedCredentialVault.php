<?php

namespace App\Services;

use App\Models\ManagedCredentialSecret;
use App\Models\Organization;
use App\Support\ManagedCredentials;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores and reads managed-database credentials as encrypted secret records.
 *
 * Each envelope is bound to its record (secret ID, organization, connection,
 * purpose), so an envelope copied onto another row fails authentication.
 */
class ManagedCredentialVault
{
    public function __construct(private readonly ManagedCredentialEncryptionService $encryption) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function store(
        Organization $organization,
        #[\SensitiveParameter] array $credentials,
        ?string $connectionId = null,
        string $purpose = ManagedCredentialSecret::PURPOSE_DATABASE_CREDENTIALS,
    ): ManagedCredentialSecret {
        $secret = new ManagedCredentialSecret([
            'managed_credential_secret_id' => (string) Str::uuid(),
            'organization_id' => $organization->organization_id,
            'connection_id' => $connectionId,
            'purpose' => $purpose,
            'status' => ManagedCredentialSecret::STATUS_ACTIVE,
        ]);
        $secret->envelope = $this->encryption->encrypt($credentials, $this->context($secret));
        $secret->save();

        return $secret;
    }

    public function reveal(ManagedCredentialSecret $secret): ManagedCredentials
    {
        return new ManagedCredentials($this->encryption->decrypt($secret->envelope, $this->context($secret)));
    }

    /**
     * Re-encrypts the record with the active key. Returns false when it already uses it.
     */
    public function rotate(ManagedCredentialSecret $secret): bool
    {
        return DB::transaction(function () use ($secret): bool {
            $locked = ManagedCredentialSecret::query()
                ->whereKey($secret->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->encryption->needsRotation($locked->envelope)) {
                return false;
            }

            $locked->envelope = $this->encryption->rotate($locked->envelope, $this->context($locked));
            $locked->save();
            $secret->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Non-secret health summary for checks and the status endpoint.
     *
     * @return array{active_key: 'ok'|'unavailable', secrets: int, needing_rotation: int, missing_key: int}
     */
    public function health(): array
    {
        try {
            $this->encryption->assertHealthy();
            $activeKey = 'ok';
        } catch (Throwable) {
            $activeKey = 'unavailable';
        }

        $configured = $this->encryption->configuredKeyIds();
        $active = $activeKey === 'ok' ? $this->encryption->activeKeyIdOrNull() : null;
        $summary = ['active_key' => $activeKey, 'secrets' => 0, 'needing_rotation' => 0, 'missing_key' => 0];

        ManagedCredentialSecret::query()
            ->where('status', '!=', ManagedCredentialSecret::STATUS_REVOKED)
            ->select(['managed_credential_secret_id', 'envelope'])
            ->orderBy('managed_credential_secret_id')
            ->chunk(500, function ($secrets) use (&$summary, $configured, $active): void {
                foreach ($secrets as $secret) {
                    $keyId = $secret->envelope['key_id'] ?? null;
                    $summary['secrets']++;
                    if (! is_string($keyId) || ! in_array($keyId, $configured, true)) {
                        $summary['missing_key']++;
                    } elseif ($active !== null && $keyId !== $active) {
                        $summary['needing_rotation']++;
                    }
                }
            });

        return $summary;
    }

    /**
     * @return array<string, string|null>
     */
    private function context(ManagedCredentialSecret $secret): array
    {
        return [
            'managed_credential_secret_id' => $secret->managed_credential_secret_id,
            'organization_id' => $secret->organization_id,
            'connection_id' => $secret->connection_id,
            'purpose' => $secret->purpose,
        ];
    }
}
