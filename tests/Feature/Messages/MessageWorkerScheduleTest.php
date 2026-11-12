<?php

namespace Tests\Feature\Messages;

use App\Models\Message;
use App\Services\MessageQueue;
use App\Services\MessageWorker;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MessageWorkerScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Message::query()->delete();
        Cache::forget(MessageWorker::LAST_RUN_CACHE_KEY);
        config(['app.status_password' => 'status-secret', 'app.status_username' => 'pane']);
        Mail::fake();
    }

    public function test_the_scheduler_runs_the_worker_every_minute_without_piling_up(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'messages:send'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertStringContainsString('--max-time=50', (string) $events[0]->command);
    }

    public function test_status_reports_the_queue_and_a_worker_that_never_ran(): void
    {
        $queue = app(MessageQueue::class);
        $old = $queue->push(Message::TYPE_SYSTEM_EMAIL, 'a@example.com', 'One', ['invitation_url' => 'https://app.test/?invitation_token=SECRET']);
        $old->forceFill(['available_at' => now()->subMinutes(10)])->save();
        $queue->push(Message::TYPE_SYSTEM_EMAIL, 'b@example.com', 'Later', [], availableAt: now()->addHour());
        $queue->push(Message::TYPE_SYSTEM_EMAIL, 'c@example.com', 'Broken', [])->forceFill(['status' => Message::STATUS_FAILED])->save();

        $response = $this->withHeader('Authorization', 'Basic '.base64_encode('pane:status-secret'))->getJson('/status')->assertOk();

        $response->assertJsonPath('data.messages.worker', 'never')
            ->assertJsonPath('data.messages.last_run_at', null)
            ->assertJsonPath('data.messages.pending', 2)
            ->assertJsonPath('data.messages.due', 1)
            ->assertJsonPath('data.messages.failed', 1)
            ->assertJsonPath('data.messages.oldest_due_age_seconds', fn (int $age): bool => $age >= 590 && $age <= 610);
        $this->assertStringNotContainsString('a@example.com', (string) $response->getContent());
        $this->assertStringNotContainsString('SECRET', (string) $response->getContent());
    }

    public function test_a_run_marks_the_worker_ok_until_it_goes_quiet(): void
    {
        $this->artisan('messages:send')->assertSuccessful();

        $health = app(MessageWorker::class)->health();
        $this->assertSame('ok', $health['worker']);
        $this->assertNotNull($health['last_run_at']);
        $this->assertNull($health['oldest_due_at']);

        $this->travel(MessageWorker::STALE_WORKER_AFTER_SECONDS + 60)->seconds();
        $this->assertSame('stale', app(MessageWorker::class)->health()['worker']);
    }
}
