<?php

namespace Tests\Feature\Messages;

use App\Messages\MessageChannel;
use App\Models\Message;
use App\Services\MessageQueue;
use App\Services\MessageWorker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class FakeChannel implements MessageChannel
{
    /** @var list<string> */
    public static array $sent = [];

    public static ?string $failWith = null;

    public function send(Message $message): void
    {
        if (self::$failWith !== null) {
            throw new RuntimeException(self::$failWith);
        }

        self::$sent[] = $message->recipient.'|'.$message->body;
    }
}

class MessageQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Message::query()->delete();
        FakeChannel::$sent = [];
        FakeChannel::$failWith = null;
        config()->set('pane.messages.channels.fake', FakeChannel::class);
    }

    public function test_push_stores_a_pending_message_with_an_encrypted_body(): void
    {
        $message = app(MessageQueue::class)->push('fake', '+390000000', null, 'secret body');

        $this->assertSame(Message::STATUS_PENDING, $message->status);
        $this->assertSame(0, $message->attempts);
        $raw = (string) DB::table($message->getTable())->where('message_id', $message->message_id)->value('body');
        $this->assertStringNotContainsString('secret body', $raw);
        $this->assertSame('secret body', Message::query()->findOrFail($message->message_id)->body);
        $this->assertArrayNotHasKey('body', $message->toArray());
    }

    public function test_push_refuses_an_unknown_channel_and_an_empty_recipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(MessageQueue::class)->push('carrier-pigeon', 'a@example.com', 's', 'b');
    }

    public function test_push_refuses_an_empty_recipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(MessageQueue::class)->push('fake', ' ', 's', 'b');
    }

    public function test_worker_sends_through_a_channel_added_without_touching_the_queue(): void
    {
        $message = app(MessageQueue::class)->push('fake', 'who', null, 'hello');

        $result = app(MessageWorker::class)->run(10, fn () => false);

        $this->assertSame([$message->message_id], $result['sent']);
        $this->assertSame(['who|hello'], FakeChannel::$sent);
        $fresh = Message::query()->findOrFail($message->message_id);
        $this->assertSame(Message::STATUS_SENT, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame(1, $fresh->attempts);
    }

    public function test_email_channel_sends_the_message_by_mail(): void
    {
        config()->set('mail.default', 'array');
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
            $sent[] = $event->message;
        });
        $message = app(MessageQueue::class)->push('email', 'a@example.com', 'Hi', 'Body text');

        app(MessageWorker::class)->run(10, fn () => false);

        $this->assertSame(Message::STATUS_SENT, $message->fresh()->status);
        $this->assertCount(1, $sent);
        $this->assertSame('Hi', $sent[0]->getSubject());
        $this->assertSame('a@example.com', $sent[0]->getTo()[0]->getAddress());
        $this->assertSame('Body text', $sent[0]->getTextBody());
    }

    public function test_a_failing_send_is_retried_with_backoff_then_marked_failed(): void
    {
        FakeChannel::$failWith = 'boom at https://x.test/t?token=abc for a@example.com';
        $message = app(MessageQueue::class)->push('fake', 'who', null, 'hello');
        $worker = app(MessageWorker::class);

        foreach ([1, 5, 30] as $attempt => $minutes) {
            $result = $worker->run(10, fn () => false);
            $this->assertSame([$message->message_id], $result['retried']);
            $fresh = $message->fresh();
            $this->assertSame(Message::STATUS_PENDING, $fresh->status);
            $this->assertSame($attempt + 1, $fresh->attempts);
            $this->assertEqualsWithDelta(now()->addMinutes($minutes)->timestamp, $fresh->available_at->timestamp, 5);
            $this->assertSame([], $worker->run(10, fn () => false)['sent'], 'not due yet');
            $this->travel($minutes + 1)->minutes();
        }

        $result = $worker->run(10, fn () => false);

        $this->assertSame([$message->message_id], $result['failed']);
        $fresh = $message->fresh();
        $this->assertSame(Message::STATUS_FAILED, $fresh->status);
        $this->assertSame(4, $fresh->attempts);
        $this->assertStringContainsString('RuntimeException: boom at [link]', (string) $fresh->last_error);
        $this->assertStringNotContainsString('a@example.com', (string) $fresh->last_error);
        $this->assertSame([], $worker->run(10, fn () => false)['failed'], 'a failed message is not picked again');
    }

    public function test_a_message_with_no_registered_channel_fails_instead_of_being_lost(): void
    {
        $message = app(MessageQueue::class)->push('fake', 'who', null, 'hello');
        config()->set('pane.messages.channels', []);

        $result = app(MessageWorker::class)->run(10, fn () => false);

        $this->assertSame([$message->message_id], $result['retried']);
        $this->assertStringContainsString('No channel registered', (string) $message->fresh()->last_error);
    }

    public function test_a_message_left_sending_by_a_crashed_run_goes_back_to_pending_and_is_sent(): void
    {
        $message = app(MessageQueue::class)->push('fake', 'who', null, 'hello');
        $worker = app(MessageWorker::class);
        $this->assertNotNull($worker->reserve($message->message_id), 'a run claims it and then dies');
        $this->assertSame([], $worker->run(10, fn () => false)['sent']);

        $this->travel(MessageWorker::STALE_AFTER_MINUTES + 1)->minutes();
        $result = $worker->run(10, fn () => false);

        $this->assertSame(1, $result['released']);
        $this->assertSame([$message->message_id], $result['sent']);
    }

    public function test_a_claimed_message_cannot_be_claimed_twice(): void
    {
        $message = app(MessageQueue::class)->push('fake', 'who', null, 'hello');
        $worker = app(MessageWorker::class);

        $this->assertNotNull($worker->reserve($message->message_id));
        $this->assertNull($worker->reserve($message->message_id));
    }

    public function test_the_limit_and_the_future_availability_are_honoured(): void
    {
        $queue = app(MessageQueue::class);
        $queue->push('fake', 'one', null, 'b');
        $queue->push('fake', 'two', null, 'b');
        $later = $queue->push('fake', 'later', null, 'b', availableAt: now()->addHour());

        $result = app(MessageWorker::class)->run(1, fn () => false);

        $this->assertCount(1, $result['sent']);
        $this->assertSame(Message::STATUS_PENDING, $later->fresh()->status);
    }

    public function test_messages_send_command_sends_and_reports(): void
    {
        app(MessageQueue::class)->push('fake', 'who', null, 'hello');

        $this->assertSame(0, Artisan::call('messages:send'));

        $this->assertStringContainsString('Sent 1, retrying 0, failed 0, released 0 stale.', Artisan::output());
        $this->assertSame(['who|hello'], FakeChannel::$sent);
    }

    public function test_messages_send_runs_on_the_schedule_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'messages:send'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
    }
}
