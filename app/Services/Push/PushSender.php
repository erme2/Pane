<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
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
        $parts = parse_url($subscription->endpoint);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        $port = is_array($parts) ? (int) ($parts['port'] ?? 443) : 443;
        $addresses = $host === '' ? [] : $this->publicAddresses($host);

        // A host that resolves to anything but public addresses is never called (DNS rebinding included).
        if ($addresses === []) {
            return [self::FAILED, 'the push service address is not public'];
        }

        // Pin the connection to the address checked above, and never follow a redirect.
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string) config('pane.push.vapid_subject'),
                'publicKey' => (string) config('pane.push.vapid_public_key'),
                'privateKey' => (string) config('pane.push.vapid_private_key'),
            ],
        ], ['TTL' => (int) config('pane.push.ttl')], new Client([
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => 30,
            'curl' => [CURLOPT_RESOLVE => ["$host:$port:$addresses[0]"]],
        ]));

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

    /**
     * The addresses the host resolves to, or none when any of them is private
     * or reserved (one bad answer rejects the host).
     *
     * @return list<string>
     */
    public function publicAddresses(string $host): array
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);

        if ($addresses === []) {
            return [];
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return [];
            }
        }

        return $addresses;
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
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
