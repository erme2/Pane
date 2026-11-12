<?php

namespace App\Console\Commands;

use App\Services\MessageWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendMessages extends Command
{
    protected $signature = 'messages:send
        {--limit=50 : Most messages to send in this run}
        {--max-time=50 : Stop starting new messages after this many seconds}
        {--once : Handle one batch and stop}';

    protected $description = 'Send due messages from the outgoing queue (run every minute from cron).';

    public function handle(MessageWorker $worker): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $deadline = microtime(true) + max(1, (int) $this->option('max-time'));

        $result = $worker->run($limit, fn (): bool => microtime(true) >= $deadline, (bool) $this->option('once'));
        $worker->recordRun();

        // IDs and counts only: never recipients, links or payloads.
        $summary = [
            'sent' => count($result['sent']),
            'retried' => count($result['retried']),
            'failed' => count($result['failed']),
            'released' => $result['released'],
        ];
        Log::info('messages:send finished', [...$summary, 'failed_ids' => $result['failed'], 'retried_ids' => $result['retried']]);
        $this->line(sprintf('Sent %d, retrying %d, failed %d, released %d stale.', ...array_values($summary)));

        return self::SUCCESS;
    }
}
