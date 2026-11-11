<?php

namespace App\Console\Commands;

use App\Models\ManagedCredentialSecret;
use App\Services\ManagedCredentialEncryptionService;
use App\Services\ManagedCredentialVault;
use Illuminate\Console\Command;
use Throwable;

class RotateManagedCredentials extends Command
{
    protected $signature = 'managed-credentials:rotate
        {--limit=500 : Maximum number of secrets to re-encrypt in this run}
        {--dry-run : Count the secrets that need re-encryption without changing them}';

    protected $description = 'Re-encrypt managed-database credentials that still use an older key with the active key.';

    public function handle(ManagedCredentialEncryptionService $encryption, ManagedCredentialVault $vault): int
    {
        try {
            $encryption->assertHealthy();
        } catch (Throwable $exception) {
            $this->error('Active managed-credential key is unavailable: '.$exception->getMessage());

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $active = (string) $encryption->activeKeyIdOrNull();
        $pending = ManagedCredentialSecret::query()
            ->where('status', '!=', ManagedCredentialSecret::STATUS_REVOKED)
            ->orderBy('managed_credential_secret_id')
            ->get(['managed_credential_secret_id', 'organization_id', 'connection_id', 'purpose', 'envelope'])
            ->filter(fn (ManagedCredentialSecret $secret): bool => ($secret->envelope['key_id'] ?? null) !== $active)
            ->values();

        if ($dryRun) {
            $this->info("{$pending->count()} secret(s) need re-encryption with key [$active].");

            return self::SUCCESS;
        }

        $rotated = 0;
        $failed = 0;
        foreach ($pending->take($limit) as $secret) {
            try {
                if ($vault->rotate($secret)) {
                    $rotated++;
                }
            } catch (Throwable $exception) {
                $failed++;
                // Exception messages never contain credential values; keep only the class and ID.
                $this->warn("Could not re-encrypt secret {$secret->managed_credential_secret_id}: ".$exception::class);
            }
        }

        $remaining = max(0, $pending->count() - $rotated - $failed);
        $this->info("Re-encrypted $rotated secret(s) with key [$active]; $failed failed; $remaining still to do.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
