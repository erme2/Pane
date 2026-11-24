<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

/**
 * Sends one payload to one device through its push service. This is the only
 * class that talks to the network, so tests replace it in the container with
 * a fake push service.
 */
class PushSender
{
    public const string DELIVERED = 'delivered';

    /** The device is gone (push service answered 404 or 410): remove the subscription, don't retry. */
    public const string GONE = 'gone';

    /** Any other refusal or failure: retry later. */
    public const string FAILED = 'failed';

    /**
     * @return array{0: string, 1: string|null} outcome and a short reason
     */
    public function send(PushSubscription $subscription, #[\SensitiveParameter] string $payload): array
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string) config('pane.push.vapid_subject'),
                'publicKey' => (string) config('pane.push.vapid_public_key'),
                'privateKey' => (string) config('pane.push.vapid_private_key'),
            ],
        ], ['TTL' => (int) config('pane.push.ttl')]);

        $report = $webPush->sendOneNotification(
            Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->p256dh,
                'authToken' => $subscription->auth,
            ]),
            $payload,
        );

        if ($report->isSuccess()) {
            return [self::DELIVERED, null];
        }

        if ($report->isSubscriptionExpired()) {
            return [self::GONE, null];
        }

        $status = $report->getResponse()?->getStatusCode();

        return [self::FAILED, $status === null ? 'no answer from the push service' : "push service answered $status"];
    }

    public function configured(): bool
    {
        return filled(config('pane.push.vapid_public_key'))
            && filled(config('pane.push.vapid_private_key'))
            && filled(config('pane.push.vapid_subject'));
    }

    public function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Web push is not configured on this install.');
        }
    }
}
