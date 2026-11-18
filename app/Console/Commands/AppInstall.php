<?php

namespace App\Console\Commands;

use App\Exceptions\AppInstall\AppInstallException;
use App\Services\AppInstaller;
use Illuminate\Console\Command;

class AppInstall extends Command
{
    protected $signature = 'app:install
        {path : Data folder with migrations/*.sql and, optionally, maps/*.json}
        {--status : List the migrations that ran and the pending ones, and change nothing}';

    protected $description = "Run an app's data folder: its pending migrations under APP_TABLE_PREFIX, then its maps.";

    public function handle(AppInstaller $installer): int
    {
        $path = (string) $this->argument('path');

        try {
            if ($this->option('status')) {
                foreach ($installer->status($path) as $migration) {
                    $this->line(($migration['ran'] ? 'ran      ' : 'pending  ').$migration['file']);
                }

                return self::SUCCESS;
            }

            $result = $installer->install($path);
        } catch (AppInstallException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(count($result['migrations']).' migration(s) ran.');
        foreach ($result['maps'] as $outcome => $names) {
            $this->info(ucfirst($outcome).' maps: '.($names === [] ? 'none' : implode(', ', $names)));
        }

        return self::SUCCESS;
    }
}
