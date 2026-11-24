<?php

namespace App\Messages;

use App\Models\Message;
use App\Models\PushSubscription;
use App\Services\Push\PushSender;
use RuntimeException;

/**
 * Delivers a queued message as a web push. The message recipient is the
 * subscription id and the body is the (JSON) payload. A device the push
 * service reports gone is removed and the message counts as done: retrying
 * could never succeed. Any other failure throws, so the worker retries.
 */
class WebPushChannel implements MessageChannel
{
    public const string NAME = 'push';

    public function __construct(private readonly PushSender $sender) {}

    public function send(Message $message): void
    {
        $subscription = PushSubscription::query()->find($message->recipient);

        // Removed by the member (or cleaned up) after the message was queued: nothing to send to.
        if (! $subscription instanceof PushSubscription) {
            return;
        }

        $this->sender->assertConfigured();

        [$outcome, $reason] = $this->sender->send($subscription, $message->body);

        if ($outcome === PushSender::GONE) {
            $subscription->delete();

            return;
        }

        if ($outcome !== PushSender::DELIVERED) {
            throw new RuntimeException($reason ?? 'Push delivery failed.');
        }

        $subscription->forceFill(['last_sent_at' => now()])->save();
    }
}
