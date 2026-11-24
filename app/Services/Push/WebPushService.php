<?php

namespace App\Services\Push;

use App\Messages\WebPushChannel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\MessageQueue;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * Building block for sending a push to members (Pane#161 declarative actions
 * call it for "send a push"). It queues one message per registered device on
 * the `push` channel; the `messages:send` worker delivers them, with the
 * queue's retries.
 *
 * The caller decides who may be notified: pass only members the access policy
 * lets see the event. This service never widens that list.
 */
class WebPushService
{
    /** Longest payload accepted, in bytes (push services refuse about 4 KB once encrypted). */
    public const int MAX_PAYLOAD_BYTES = 3000;

    public function __construct(private readonly MessageQueue $queue, private readonly PushSender $sender) {}

    /**
     * Queues a push to every device of the member.
     *
     * @param  array<string, mixed>  $payload  what the app puts in the notification (title, body, url, ...)
     * @return int how many device messages were queued (0 when the member has no device or is over the rate limit)
     */
    public function sendToUser(User $user, array $payload): int
    {
        $json = $this->encode($payload);
        $this->sender->assertConfigured();

        $key = 'push:user:'.$user->getKey();

        // One push per call counts against the member, whatever their number of devices.
        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('pane.push.rate_limit_per_minute')))) {
            return 0;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $user->getKey())->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        RateLimiter::hit($key, 60);

        foreach ($subscriptions as $subscription) {
            $this->queue->push(WebPushChannel::NAME, $subscription->subscription_id, null, $json, $subscription);
        }

        return $subscriptions->count();
    }

    /**
     * Queues a push to each member of a group (already filtered by the caller).
     *
     * @param  iterable<User>  $users
     * @param  array<string, mixed>  $payload
     * @return int how many device messages were queued
     */
    public function sendToUsers(iterable $users, array $payload): int
    {
        $queued = 0;

        foreach ($users as $user) {
            $queued += $this->sendToUser($user, $payload);
        }

        return $queued;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('The push payload is invalid or longer than '.self::MAX_PAYLOAD_BYTES.' bytes.');
        }

        return $json;
    }
}
