<?php

namespace App\Console\Commands;

use App\Services\ManagedCredentialVault;
use Illuminate\Console\Command;

class CheckManagedCredentials extends Command
{
    protected $signature = 'managed-credentials:check';

    protected $description = 'Check that the managed-credential keys are configured and every stored secret can be decrypted.';

    public function handle(ManagedCredentialVault $vault): int
    {
        $health = $vault->health();

        $this->line("Active key: {$health['active_key']}");
        $this->line("Stored secrets: {$health['secrets']}");
        $this->line("Using an older key: {$health['needing_rotation']}");
        $this->line("Using a key that is not configured: {$health['missing_key']}");

        if ($health['active_key'] !== 'ok') {
            $this->error('Set PANE_MANAGED_CREDENTIAL_ACTIVE_KEY_ID and PANE_MANAGED_CREDENTIAL_KEYS (see docs/environment.md).');

            return self::FAILURE;
        }

        if ($health['missing_key'] > 0) {
            $this->error('Some secrets use a key that is no longer configured: restore that key in PANE_MANAGED_CREDENTIAL_KEYS.');

            return self::FAILURE;
        }

        if ($health['needing_rotation'] > 0) {
            $this->warn('Run `php artisan managed-credentials:rotate` before removing old keys.');
        }

        return self::SUCCESS;
    }
}
