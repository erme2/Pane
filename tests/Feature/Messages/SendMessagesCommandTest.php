<?php

namespace Tests\Feature\Messages;

use App\Mail\QueuedMessageMail;
use App\Models\Message;
use App\Services\MessageQueue;
use App\Services\MessageWorker;
use App\Support\Messages\SubjectPrefix;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class SendMessagesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Message::query()->delete();
        config()->set('services.messages.environment', 'prod');
        Mail::fake();
    }

    private function queue(string $subject = "You're invited to Sweegly", array $payload = [], ?\DateTimeInterface $at = null): Message
    {
        return app(MessageQueue::class)->push(
            Message::TYPE_SYSTEM_EMAIL,
            'invitee@example.com',
            $subject,
            $payload ?: ['invitation_url' => 'https://app.test/auth/login?invitation_token=SECRET', 'organization_name' => 'Sweegly', 'role' => 'user', 'expires_at' => '2026-11-19T10:00:00Z'],
            Message::PURPOSE_INVITATION,
            availableAt: $at,
        );
    }

    public function test_sends_due_messages_and_marks_them_sent(): void
    {
        $message = $this->queue();

        $this->artisan('messages:send')->expectsOutputToContain('Sent 1')->assertSuccessful();

        Mail::assertSent(QueuedMessageMail::class, function (QueuedMessageMail $mail): bool {
            $mail->build();

            return $mail->hasTo('invitee@example.com')
                && $mail->subject === "You're invited to Sweegly"
                && str_contains($mail->render(), 'href="https://app.test/auth/login?invitation_token=SECRET"')
                && str_contains($mail->render(), 'Sweegly</strong> as user');
        });
        $message->refresh();
        $this->assertSame(Message::STATUS_SENT, $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertSame(1, $message->attempts);
    }

    public function test_skips_messages_that_are_not_due(): void
    {
        $message = $this->queue(at: now()->addMinutes(5));

        $this->artisan('messages:send')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(Message::STATUS_PENDING, $message->refresh()->status);
    }

    public function test_failures_back_off_then_fail_after_the_last_attempt(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP refused invitee@example.com for https://app.test/?invitation_token=SECRET'));
        $message = $this->queue();

        foreach ([1, 5, 30] as $attempt => $minutes) {
            $this->artisan('messages:send')->assertSuccessful();
            $message->refresh();
            $this->assertSame(Message::STATUS_PENDING, $message->status);
            $this->assertSame($attempt + 1, $message->attempts);
            $this->assertEqualsWithDelta(now()->addMinutes($minutes)->getTimestamp(), $message->available_at->getTimestamp(), 5);
            $this->assertStringNotContainsString('SECRET', (string) $message->last_error);
            $this->assertStringNotContainsString('invitee@example.com', (string) $message->last_error);
            $this->travel($minutes)->minutes();
        }

        $this->artisan('messages:send')->expectsOutputToContain('failed 1')->assertSuccessful();
        $message->refresh();
        $this->assertSame(Message::STATUS_FAILED, $message->status);
        $this->assertSame(MessageWorker::MAX_ATTEMPTS, $message->attempts);
        $this->assertStringStartsWith('RuntimeException: SMTP refused [address] for [link]', (string) $message->last_error);
    }

    public function test_a_message_can_only_be_claimed_once(): void
    {
        $message = $this->queue();
        $worker = app(MessageWorker::class);

        $this->assertNotNull($worker->reserve($message->message_id));
        $this->assertNull($worker->reserve($message->message_id));

        // A second run finds nothing to send while the first holds it.
        $this->artisan('messages:send')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_stale_reservations_are_released_and_sent(): void
    {
        $message = $this->queue();
        app(MessageWorker::class)->reserve($message->message_id);
        $this->travel(MessageWorker::STALE_AFTER_MINUTES + 1)->minutes();

        $this->artisan('messages:send')->expectsOutputToContain('released 1')->assertSuccessful();

        Mail::assertSentCount(1);
        $this->assertSame(Message::STATUS_SENT, $message->refresh()->status);
    }

    public function test_limit_and_once_bound_a_run(): void
    {
        foreach (range(1, 15) as $i) {
            $this->queue("Message $i");
        }

        $this->artisan('messages:send', ['--limit' => 3])->assertSuccessful();
        Mail::assertSentCount(3);

        $this->artisan('messages:send', ['--once' => true])->assertSuccessful();
        Mail::assertSentCount(13);

        $this->artisan('messages:send')->assertSuccessful();
        Mail::assertSentCount(15);
    }

    public function test_logs_counts_and_ids_but_never_recipients_or_links(): void
    {
        Log::spy();
        $this->queue();

        $this->artisan('messages:send')->assertSuccessful();

        // @phpstan-ignore staticMethod.notFound
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context);

            return $message === 'messages:send finished' && $context['sent'] === 1
                && ! str_contains((string) $encoded, 'invitee@') && ! str_contains((string) $encoded, 'SECRET');
        });
    }

    public function test_dev_and_stage_subjects_get_a_prefix_once(): void
    {
        $this->assertSame('[DEV] Hello', SubjectPrefix::apply('Hello', 'dev'));
        $this->assertSame('[DEV] Hello', SubjectPrefix::apply('Hello', 'development'));
        $this->assertSame('[STAGE] Hello', SubjectPrefix::apply('Hello', 'staging'));
        $this->assertSame('[DEV] Hello', SubjectPrefix::apply('[DEV] Hello', 'dev'));
        foreach (['prod', 'production', 'local', 'testing'] as $environment) {
            $this->assertSame('Hello', SubjectPrefix::apply('Hello', $environment));
        }

        // PANE_ENVIRONMENT wins over APP_ENV, which is production on deployed dev installs.
        config()->set('services.messages.environment', 'stage');
        config()->set('app.env', 'production');
        $this->assertSame('[STAGE] Hello', SubjectPrefix::apply('Hello'));
        config()->set('services.messages.environment', null);
        config()->set('app.env', 'dev');
        $this->assertSame('[DEV] Hello', SubjectPrefix::apply('Hello'));
    }

    public function test_the_sent_email_carries_the_environment_prefix(): void
    {
        config()->set('services.messages.environment', 'dev');
        $this->queue();

        $this->artisan('messages:send')->assertSuccessful();

        Mail::assertSent(QueuedMessageMail::class, fn (QueuedMessageMail $mail): bool => $mail->build()->subject === "[DEV] You're invited to Sweegly");
        $this->assertSame("You're invited to Sweegly", Message::query()->sole()->subject);
    }

    public function test_payload_values_are_escaped_and_only_web_links_are_linked(): void
    {
        $this->queue(payload: ['organization_name' => '<script>x</script>', 'invitation_url' => 'javascript:alert(1)']);

        $this->artisan('messages:send')->assertSuccessful();

        Mail::assertSent(QueuedMessageMail::class, function (QueuedMessageMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, '&lt;script&gt;') && ! str_contains($html, '<script>') && ! str_contains($html, 'javascript:');
        });
    }
}
