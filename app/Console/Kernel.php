<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // One cron entry (`php artisan schedule:run` every minute) drives this; see docs/hostinger.md.
        // Overlapping runs are safe anyway (each message is claimed once); the lock just avoids piling up.
        $schedule->command('messages:send --limit=50 --max-time=50')
            ->everyMinute()
            ->withoutOverlapping(5);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
