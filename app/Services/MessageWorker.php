<?php

namespace App\Services;

use App\Messages\MessageChannel;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Sends due messages from the queue. Safe to run from overlapping cron jobs:
 * each message is claimed with a conditional UPDATE (`WHERE status = pending`),
 * so only one run can send it. A failed send is retried after 1, 5 and 30
 * minutes, then marked failed. A message stuck in `sending` (a run that
 * crashed) goes back to pending after STALE_AFTER_MINUTES, so none is lost.
 */
class MessageWorker
{
    public const int MAX_ATTEMPTS = 4;

    /** Minutes before retry N (after attempt N failed). */
    public const array BACKOFF_MINUTES = [1, 5, 30];

    public const int STALE_AFTER_MINUTES = 10;

    /**
     * @param  callable(): bool  $shouldStop  checked between messages (time budget)
     * @return array{sent: list<string>, retried: list<string>, failed: list<string>, released: int}
     */
    public function run(int $limit, callable $shouldStop): array
    {
        $result = ['sent' => [], 'retried' => [], 'failed' => [], 'released' => $this->releaseStale()];

        foreach ($this->dueIds($limit) as $id) {
            // Maintenance mode (a restore) stops the claim, so a run already in progress stops writing.
            if ($shouldStop() || app()->isDownForMaintenance()) {
                break;
            }

            $message = $this->reserve($id);
            if (! $message instanceof Message) {
                continue; // Another run took it.
            }

            $result[$this->send($message)][] = $id;
        }

        return $result;
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
            $this->channel($message->channel)->send($message);
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

    private function channel(string $name): MessageChannel
    {
        $class = config("pane.messages.channels.$name");
        $channel = is_string($class) ? app($class) : null;

        if (! $channel instanceof MessageChannel) {
            throw new RuntimeException("No channel registered for [$name].");
        }

        return $channel;
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
     * Short error text without links or addresses (a transport error can
     * echo either; links can be credentials).
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
