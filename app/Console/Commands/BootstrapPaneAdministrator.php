<?php

namespace App\Console\Commands;

use App\Services\PaneAdminLifecycleService;
use DomainException;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BootstrapPaneAdministrator extends Command
{
    protected $signature = 'pane:bootstrap-admin
        {email : Email address for the first Pane administrator}
        {--name= : Display name for the first Pane administrator}';

    protected $description = 'Create the first Pane administrator from the server side.';

    public function handle(PaneAdminLifecycleService $administrators): int
    {
        try {
            $user = $administrators->bootstrapFirstAdministrator(
                (string) $this->argument('email'),
                $this->option('name') !== null ? (string) $this->option('name') : null
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Pane administrator ready: {$user->email}");

        return self::SUCCESS;
    }
}
