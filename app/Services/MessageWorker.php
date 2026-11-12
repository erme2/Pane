<?php

namespace App\Services;

use App\Mail\QueuedMessageMail;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends due messages from the queue. Safe to run from overlapping cron jobs:
 * each message is claimed with a conditional UPDATE (`WHERE status = pending`),
 * so only one run can send it. A failed send is retried after 1, 5 and 30
 * minutes, then marked failed. Messages stuck in `sending` (a run that
 * crashed) go back to pending after STALE_AFTER_MINUTES.
 */
class MessageWorker
{
    public const int MAX_ATTEMPTS = 4;

    /** Minutes before retry N (after attempt N failed). */
    public const array BACKOFF_MINUTES = [1, 5, 30];

    public const int STALE_AFTER_MINUTES = 10;

    private const int BATCH_SIZE = 10;

    /** Cache key holding when the worker last finished a run (ISO 8601). */
    public const string LAST_RUN_CACHE_KEY = 'pane:messages:last_run_at';

    /** A worker that hasn't run for this long is reported as stale. */
    public const int STALE_WORKER_AFTER_SECONDS = 300;

    /**
     * @param  callable(): bool  $shouldStop  checked between messages (time budget)
     * @return array{sent: list<string>, retried: list<string>, failed: list<string>, released: int}
     */
    public function run(int $limit, callable $shouldStop, bool $once = false): array
    {
        $result = ['sent' => [], 'retried' => [], 'failed' => [], 'released' => $this->releaseStale()];
        $handled = 0;

        do {
            $batch = $this->dueIds(min(self::BATCH_SIZE, $limit - $handled));

            foreach ($batch as $id) {
                if ($handled >= $limit || $shouldStop()) {
                    return $result;
                }

                $message = $this->reserve($id);
                if (! $message instanceof Message) {
                    continue; // Another run took it.
                }

                $handled++;
                $outcome = $this->send($message);
                $result[$outcome][] = $id;
            }
        } while (! $once && $batch !== [] && $handled < $limit);

        return $result;
    }

    /** Records that a run finished, for the status endpoint. */
    public function recordRun(): void
    {
        Cache::forever(self::LAST_RUN_CACHE_KEY, now()->toJSON());
    }

    /**
     * Queue and worker health for `/status`: counts only, no recipients.
     * `worker` is `ok`, `stale` (no run for 5 minutes) or `never`.
     *
     * @return array{worker: 'ok'|'stale'|'never', last_run_at: string|null, pending: int, due: int, sending: int, failed: int, oldest_due_at: string|null, oldest_due_age_seconds: int|null}
     */
    public function health(): array
    {
        $lastRun = Cache::get(self::LAST_RUN_CACHE_KEY);
        $lastRunAt = is_string($lastRun) ? Carbon::parse($lastRun) : null;
        $oldestDue = Message::query()
            ->where('status', Message::STATUS_PENDING)
            ->where('available_at', '<=', now())
            ->min('available_at');
        $oldestDueAt = $oldestDue !== null ? Carbon::parse((string) $oldestDue) : null;
        $counts = Message::query()
            ->whereIn('status', [Message::STATUS_PENDING, Message::STATUS_SENDING, Message::STATUS_FAILED])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'worker' => match (true) {
                $lastRunAt === null => 'never',
                $lastRunAt->lt(now()->subSeconds(self::STALE_WORKER_AFTER_SECONDS)) => 'stale',
                default => 'ok',
            },
            'last_run_at' => $lastRunAt?->toJSON(),
            'pending' => (int) ($counts[Message::STATUS_PENDING] ?? 0),
            'due' => Message::query()->where('status', Message::STATUS_PENDING)->where('available_at', '<=', now())->count(),
            'sending' => (int) ($counts[Message::STATUS_SENDING] ?? 0),
            'failed' => (int) ($counts[Message::STATUS_FAILED] ?? 0),
            'oldest_due_at' => $oldestDueAt?->toJSON(),
            'oldest_due_age_seconds' => $oldestDueAt !== null ? max(0, (int) $oldestDueAt->diffInSeconds(now())) : null,
        ];
    }

    /**
     * Claims a pending, due message for this run. Null when another run
     * already claimed it, or it's no longer due.
     */
    public function reserve(string $messageId): ?Message
    {
        $now = now();
        $claimed = Message::query()
            ->whereKey($messageId)
            ->where('status', Message::STATUS_PENDING)
            ->where('available_at', '<=', $now)
            ->update([
                'status' => Message::STATUS_SENDING,
                'reserved_at' => $now,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);

        return $claimed === 1 ? Message::query()->find($messageId) : null;
    }

    /** Puts messages stuck in `sending` back in the queue. */
    public function releaseStale(): int
    {
        return Message::query()
            ->where('status', Message::STATUS_SENDING)
            ->where('reserved_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update(['status' => Message::STATUS_PENDING, 'reserved_at' => null, 'available_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return 'sent'|'retried'|'failed'
     */
    private function send(Message $message): string
    {
        try {
            Mail::to($message->recipient)->send(new QueuedMessageMail($message));
        } catch (Throwable $exception) {
            return $this->recordFailure($message, $exception);
        }

        $message->forceFill([
            'status' => Message::STATUS_SENT,
            'sent_at' => now(),
            'reserved_at' => null,
            'last_error' => null,
        ])->save();

        return 'sent';
    }

    /**
     * @return 'retried'|'failed'
     */
    private function recordFailure(Message $message, Throwable $exception): string
    {
        $failed = $message->attempts >= self::MAX_ATTEMPTS;
        $delay = self::BACKOFF_MINUTES[max(1, min($message->attempts, count(self::BACKOFF_MINUTES))) - 1];

        $message->forceFill([
            'status' => $failed ? Message::STATUS_FAILED : Message::STATUS_PENDING,
            'reserved_at' => null,
            'available_at' => $failed ? $message->available_at : now()->addMinutes($delay),
            'last_error' => $this->safeError($exception),
        ])->save();

        return $failed ? 'failed' : 'retried';
    }

    /**
     * Short error text without links or email addresses (a transport error
     * can echo either; links can be credentials).
     */
    private function safeError(Throwable $exception): string
    {
        $text = preg_replace(['~[a-z][a-z0-9+.-]*://\S+~i', '/[^\s@<>"]+@[^\s@<>"]+/'], ['[link]', '[address]'], $exception->getMessage()) ?? '';

        return mb_substr(class_basename($exception).': '.trim($text), 0, 300);
    }

    /**
     * @return list<string>
     */
    private function dueIds(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        /** @var list<string> */
        return Message::query()
            ->where('status', Message::STATUS_PENDING)
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->orderBy('created_at')
            ->limit($count)
            ->pluck('message_id')
            ->all();
    }
}
