<?php

namespace Tests\Feature\Messages;

use App\Models\Message;
use App\Services\MessageQueue;
use InvalidArgumentException;
use Tests\TestCase;

class MessageQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Message::query()->delete();
    }

    public function test_push_adds_a_pending_email_that_is_due_now(): void
    {
        $message = app(MessageQueue::class)->push(Message::TYPE_MARKETING_EMAIL, 'reader@example.com', 'News', ['headline' => 'Hello']);

        $message->refresh();
        $this->assertSame(Message::STATUS_PENDING, $message->status);
        $this->assertSame(0, $message->attempts);
        $this->assertSame(['headline' => 'Hello'], $message->payload);
        $this->assertTrue($message->available_at->lessThanOrEqualTo(now()));
        $this->assertNull($message->organization_id);
    }

    public function test_push_can_delay_a_message(): void
    {
        $message = app(MessageQueue::class)->push(Message::TYPE_SYSTEM_EMAIL, 'reader@example.com', 'Later', [], availableAt: now()->addHour());

        $this->assertTrue($message->refresh()->available_at->isFuture());
    }

    public function test_unknown_types_and_invalid_recipients_are_rejected(): void
    {
        foreach ([['sms', 'reader@example.com'], [Message::TYPE_SYSTEM_EMAIL, 'not-an-email']] as [$type, $recipient]) {
            try {
                app(MessageQueue::class)->push($type, $recipient, 'Subject', []);
                $this->fail("[$type, $recipient] should be rejected");
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame(0, Message::query()->count());
    }
}
