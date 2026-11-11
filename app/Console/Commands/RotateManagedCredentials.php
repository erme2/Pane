<?php

namespace App\Console\Commands;

use App\Models\ManagedCredentialSecret;
use App\Services\ManagedCredentialEncryptionService;
use App\Services\ManagedCredentialVault;
use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;
use Throwable;

class RotateManagedCredentials extends Command
{
    private const int CHUNK_SIZE = 100;

    private const array COLUMNS = ['managed_credential_secret_id', 'organization_id', 'connection_id', 'purpose', 'envelope'];

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
        $pending = $this->pendingSecrets($active);

        if ($dryRun) {
            $this->info("{$pending->count()} secret(s) need re-encryption with key [$active].");

            return self::SUCCESS;
        }

        $total = $pending->count();
        $attempted = 0;
        $rotated = 0;
        $failed = 0;
        foreach ($pending as $secret) {
            if ($attempted >= $limit) {
                break;
            }

            $attempted++;
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

        $remaining = max(0, $total - $rotated - $failed);
        $this->info("Re-encrypted $rotated secret(s) with key [$active]; $failed failed; $remaining still to do.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Non-revoked secrets whose envelope uses a key other than the active one.
     * Read in keyset-paginated chunks so memory stays bounded as the table grows;
     * enumerating it again re-runs the query.
     *
     * @return LazyCollection<int, ManagedCredentialSecret>
     */
    private function pendingSecrets(string $active): LazyCollection
    {
        return ManagedCredentialSecret::query()
            ->where('status', '!=', ManagedCredentialSecret::STATUS_REVOKED)
            ->select(self::COLUMNS)
            ->lazyById(self::CHUNK_SIZE, 'managed_credential_secret_id')
            ->filter(fn (ManagedCredentialSecret $secret): bool => ($secret->envelope['key_id'] ?? null) !== $active);
    }
}
