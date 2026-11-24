<?php

namespace Tests\Feature\Push;

use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\MessageWorker;
use App\Services\Push\PushSender;
use App\Services\Push\WebPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Tests\TestCase;

/** A push service that answers what the test says, and records what it was sent. */
class FakePushSender extends PushSender
{
    /** @var list<string> */
    public array $sent = [];

    /** @var array<string, array{0: string, 1: string|null}> endpoint => outcome */
    public array $outcomes = [];

    public function send(PushSubscription $subscription, string $payload): array
    {
        $this->sent[] = $subscription->endpoint.'|'.$payload;

        return $this->outcomes[$subscription->endpoint] ?? [self::DELIVERED, null];
    }
}

class WebPushTest extends TestCase
{
    private FakePushSender $push;

    protected function setUp(): void
    {
        parent::setUp();

        PushSubscription::query()->delete();
        Message::query()->delete();
        RateLimiter::clear('push:user:1');

        config()->set('pane.push.vapid_public_key', 'BPublicKeyForTests');
        config()->set('pane.push.vapid_private_key', 'privateKeyForTests');
        config()->set('pane.push.vapid_subject', 'mailto:ops@example.com');

        $this->push = new FakePushSender;
        $this->app->instance(PushSender::class, $this->push);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $host = 'push.example.com', string $path = '/send/abc'): array
    {
        return [
            'endpoint' => "https://$host$path",
            'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
            'device_label' => 'Pixel',
        ];
    }

    private function signedIn(): User
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($user);

        return $user;
    }

    public function test_a_member_registers_lists_and_removes_a_device(): void
    {
        $user = $this->signedIn();

        $id = $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body())
            ->assertCreated()
            ->assertJsonPath('data.device_label', 'Pixel')
            ->assertJsonPath('data.service', 'push.example.com')
            ->assertJsonMissingPath('data.endpoint')
            ->assertJsonMissingPath('data.keys')
            ->json('data.id');

        $this->asLatte()->getJson('/api/v1/push/subscriptions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);

        $this->assertSame($user->getKey(), PushSubscription::query()->find($id)->user_id);

        $this->asLatte()->deleteJson("/api/v1/push/subscriptions/$id")->assertNoContent();
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_registering_the_same_endpoint_refreshes_it_instead_of_adding_one(): void
    {
        $this->signedIn();

        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body())->assertCreated();
        $second = $this->body();
        $second['keys']['auth'] = 'newAuthKey';
        $second['device_label'] = 'Renamed';

        $this->asLatte()->postJson('/api/v1/push/subscriptions', $second)
            ->assertOk()
            ->assertJsonPath('data.device_label', 'Renamed');

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame('newAuthKey', PushSubscription::query()->first()->auth);
    }

    public function test_a_device_moved_to_another_member_does_not_receive_the_first_members_queued_push(): void
    {
        $a = $this->signedIn();
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body())->assertCreated();
        $oldId = PushSubscription::query()->first()->subscription_id;

        app(WebPushService::class)->sendToUser($a, ['title' => 'Private to A']);

        $b = $this->signedIn();
        $this->assertNotSame($a->getKey(), $b->getKey());
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body())->assertCreated();

        $moved = PushSubscription::query()->first();
        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame($b->getKey(), $moved->user_id);
        $this->assertNotSame($oldId, $moved->subscription_id);

        app(MessageWorker::class)->run(10, fn (): bool => false);

        $this->assertSame([], $this->push->sent, 'A\'s push must not reach B\'s registration');
    }

    public function test_only_public_addresses_are_called(): void
    {
        $resolver = new class extends PushSender
        {
            /** @var array<string, list<string>> */
            public array $dns = [];

            protected function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        };
        $resolver->dns = [
            'public.example.com' => ['93.184.216.34'],
            'private.example.com' => ['10.0.0.5'],
            '127.0.0.1.nip.io' => ['127.0.0.1'],
            'mixed.example.com' => ['93.184.216.34', '192.168.1.9'],
        ];

        $this->assertSame(['93.184.216.34'], $resolver->publicAddresses('public.example.com'));
        $this->assertSame([], $resolver->publicAddresses('private.example.com'));
        $this->assertSame([], $resolver->publicAddresses('127.0.0.1.nip.io'));
        $this->assertSame([], $resolver->publicAddresses('mixed.example.com'));
        $this->assertSame([], $resolver->publicAddresses('unknown.example.com'));
        $this->assertSame([], $resolver->publicAddresses('127.0.0.1'));

        $subscription = new PushSubscription(['endpoint' => 'https://private.example.com/x', 'p256dh' => 'a', 'auth' => 'b']);
        [$outcome] = $resolver->send($subscription, '{}');
        $this->assertSame(PushSender::FAILED, $outcome);
    }

    public function test_keys_are_encrypted_at_rest(): void
    {
        $this->signedIn();
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body())->assertCreated();

        $raw = DB::table((new PushSubscription)->getTable())->first();

        $this->assertStringNotContainsString('tBHItJI5svbpez7KI4CCXg', $raw->auth);
        $this->assertStringNotContainsString('BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA', $raw->p256dh);
    }

    public function test_a_member_cannot_see_or_remove_another_members_device(): void
    {
        $other = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $theirs = PushSubscription::query()->create([
            'user_id' => $other->getKey(), 'kind' => 'web', 'endpoint' => 'https://push.example.com/x',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example.com/x'), 'p256dh' => 'a', 'auth' => 'b',
        ]);

        $this->signedIn();

        $this->asLatte()->getJson('/api/v1/push/subscriptions')->assertOk()->assertJsonCount(0, 'data');
        $this->asLatte()->deleteJson("/api/v1/push/subscriptions/{$theirs->subscription_id}")->assertNotFound();
        $this->assertNotNull(PushSubscription::query()->find($theirs->subscription_id));
    }

    public function test_an_unsafe_or_malformed_registration_is_refused(): void
    {
        $this->signedIn();

        foreach ([
            ['endpoint' => 'http://push.example.com/x'],
            ['endpoint' => 'https://localhost/x'],
            ['endpoint' => 'https://10.0.0.5/x'],
            ['endpoint' => 'https://user:pass@push.example.com/x'],
            ['endpoint' => 'https://push.example.com/x', 'keys' => ['p256dh' => 'a b', 'auth' => 'c']],
            ['endpoint' => 'https://push.example.com/x', 'keys' => ['p256dh' => 'a']],
            ['extra' => 1],
        ] as $override) {
            $body = array_merge($this->body(), $override);
            $this->asLatte()->postJson('/api/v1/push/subscriptions', $body)->assertStatus(422);
        }

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_the_number_of_devices_per_member_is_limited(): void
    {
        $this->signedIn();
        config()->set('pane.push.max_subscriptions_per_user', 2);

        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body(path: '/1'))->assertCreated();
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body(path: '/2'))->assertCreated();
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body(path: '/3'))
            ->assertStatus(422)->assertJsonPath('error.code', 'too_many_devices');
        // Refreshing a known device still works at the limit.
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body(path: '/2'))->assertOk();
    }

    public function test_registration_needs_a_sign_in_and_a_configured_install(): void
    {
        $this->withCsrfToken()->asLatte()->postJson('/api/v1/push/subscriptions', $this->body())->assertUnauthorized();

        $this->signedIn();
        config()->set('pane.push.vapid_private_key', '');

        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body())
            ->assertStatus(503)->assertJsonPath('error.code', 'push_unavailable');
        $this->asLatte()->getJson('/api/v1/push')->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.vapid_public_key', null);
    }

    public function test_config_gives_the_public_key_only(): void
    {
        $this->signedIn();

        $this->asLatte()->getJson('/api/v1/push')->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.vapid_public_key', 'BPublicKeyForTests')
            ->assertJsonMissingPath('data.vapid_private_key');
    }

    public function test_send_queues_one_message_per_device_and_the_worker_delivers_them(): void
    {
        $user = $this->signedIn();
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body(path: '/1'))->assertCreated();
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body(path: '/2'))->assertCreated();

        $queued = app(WebPushService::class)->sendToUser($user, ['title' => 'Anna answered']);

        $this->assertSame(2, $queued);
        $this->assertSame([], $this->push->sent, 'nothing is sent while queuing');

        $result = app(MessageWorker::class)->run(10, fn (): bool => false);

        $this->assertCount(2, $result['sent']);
        $this->assertCount(2, $this->push->sent);
        $this->assertStringContainsString('{"title":"Anna answered"}', $this->push->sent[0]);
        $this->assertNotNull(PushSubscription::query()->first()->last_sent_at);
    }

    public function test_send_only_reaches_the_given_members(): void
    {
        $mine = $this->signedIn();
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body(path: '/mine'))->assertCreated();
        $other = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        PushSubscription::query()->create([
            'user_id' => $other->getKey(), 'kind' => 'web', 'endpoint' => 'https://push.example.com/other',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://push.example.com/other'), 'p256dh' => 'a', 'auth' => 'b',
        ]);

        $this->assertSame(1, app(WebPushService::class)->sendToUsers([$mine], ['title' => 't']));
        app(MessageWorker::class)->run(10, fn (): bool => false);

        $this->assertCount(1, $this->push->sent);
        $this->assertStringContainsString('/mine', $this->push->sent[0]);
    }

    public function test_a_gone_device_is_removed_and_not_retried(): void
    {
        $user = $this->signedIn();
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body())->assertCreated();
        $this->push->outcomes['https://push.example.com/send/abc'] = [PushSender::GONE, null];

        app(WebPushService::class)->sendToUser($user, ['title' => 't']);
        $result = app(MessageWorker::class)->run(10, fn (): bool => false);

        $this->assertCount(1, $result['sent']);
        $this->assertSame(0, PushSubscription::query()->count());
        $this->assertSame(0, Message::query()->where('status', Message::STATUS_PENDING)->count());
    }

    public function test_a_failed_push_is_retried_then_marked_failed(): void
    {
        $user = $this->signedIn();
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body())->assertCreated();
        $this->push->outcomes['https://push.example.com/send/abc'] = [PushSender::FAILED, 'push service answered 503'];

        app(WebPushService::class)->sendToUser($user, ['title' => 't']);

        $first = app(MessageWorker::class)->run(10, fn (): bool => false);
        $this->assertCount(1, $first['retried']);

        $message = Message::query()->first();
        $this->assertSame(Message::STATUS_PENDING, $message->status);
        $this->assertSame('RuntimeException: push service answered 503', $message->last_error);
        $this->assertSame(1, PushSubscription::query()->count(), 'a failure is not a gone device');

        for ($attempt = 2; $attempt <= MessageWorker::MAX_ATTEMPTS; $attempt++) {
            Message::query()->update(['available_at' => now()->subMinute()]);
            app(MessageWorker::class)->run(10, fn (): bool => false);
        }

        $this->assertSame(Message::STATUS_FAILED, Message::query()->first()->status);
    }

    public function test_a_device_removed_after_queuing_is_skipped(): void
    {
        $user = $this->signedIn();
        $id = $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body())->json('data.id');

        app(WebPushService::class)->sendToUser($user, ['title' => 't']);
        PushSubscription::query()->whereKey($id)->delete();
        $result = app(MessageWorker::class)->run(10, fn (): bool => false);

        $this->assertCount(1, $result['sent']);
        $this->assertSame([], $this->push->sent);
    }

    public function test_pushes_per_member_are_rate_limited(): void
    {
        $user = $this->signedIn();
        $this->asLatte()->postJson('/api/v1/push/subscriptions', $this->body())->assertCreated();
        config()->set('pane.push.rate_limit_per_minute', 2);
        RateLimiter::clear('push:user:'.$user->getKey());

        $service = app(WebPushService::class);

        $this->assertSame(1, $service->sendToUser($user, ['n' => 1]));
        $this->assertSame(1, $service->sendToUser($user, ['n' => 2]));
        $this->assertSame(0, $service->sendToUser($user, ['n' => 3]));
        $this->assertSame(2, Message::query()->count());
    }

    public function test_a_member_without_devices_gets_nothing_and_a_bad_payload_is_refused(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $service = app(WebPushService::class);

        $this->assertSame(0, $service->sendToUser($user, ['title' => 't']));

        $this->expectException(InvalidArgumentException::class);
        $service->sendToUser($user, ['body' => str_repeat('x', 4000)]);
    }

    public function test_sending_is_refused_when_push_is_not_configured(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        config()->set('pane.push.vapid_public_key', '');

        $this->expectException(\RuntimeException::class);
        app(WebPushService::class)->sendToUser($user, ['title' => 't']);
    }

    private function asLatte(): static
    {
        return $this->withHeader('Origin', 'https://latte.localhost')->withV1ApplicationSession();
    }
}
