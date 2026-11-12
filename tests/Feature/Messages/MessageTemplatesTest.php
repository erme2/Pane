<?php

namespace Tests\Feature\Messages;

use App\Mail\QueuedMessageMail;
use App\Models\Message;
use App\Support\Messages\MessageTemplates;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MessageTemplatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.messages.environment' => 'prod', 'mail.from.name' => 'Sweegly']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function message(string $type, ?string $purpose, array $payload): Message
    {
        return new Message(['type' => $type, 'purpose' => $purpose, 'recipient' => 'a@example.com', 'subject' => 'Hello', 'payload' => $payload]);
    }

    /**
     * @return array{html: string, text: string, subject: string}
     */
    private function render(Message $message): array
    {
        $mail = (new QueuedMessageMail($message))->build();

        return [
            'html' => $mail->render(),
            'text' => (string) view($mail->textView, $mail->buildViewData())->render(),
            'subject' => (string) $mail->subject,
        ];
    }

    public function test_the_invitation_template_renders_html_and_text(): void
    {
        $url = 'https://app.test/auth/login?invitation_token=abc&redirect_to=https%3A%2F%2Fapp.test';
        $email = $this->render($this->message(Message::TYPE_SYSTEM_EMAIL, Message::PURPOSE_INVITATION, [
            'invitation_url' => $url, 'organization_name' => 'Sweegly', 'role' => 'administrator', 'expires_at' => '2026-11-19T10:00:00Z',
        ]));

        $this->assertStringContainsString('Accept the invitation', $email['html']);
        $this->assertStringContainsString('href="'.e($url).'"', $email['html']);
        $this->assertStringContainsString('<strong>Sweegly</strong> as administrator', $email['html']);
        $this->assertStringContainsString('19 November 2026, 10:00 UTC', $email['html']);
        $this->assertStringContainsString('Sent by Sweegly', $email['html']);
        // The plain-text link must stay usable: no &amp;.
        $this->assertStringContainsString("Accept the invitation: $url", $email['text']);
        $this->assertStringNotContainsString('environment: not a production email', $email['html']);
    }

    public function test_marketing_email_has_its_template_and_an_unsubscribe_placeholder(): void
    {
        $email = $this->render($this->message(Message::TYPE_MARKETING_EMAIL, null, ['headline' => 'Big news', 'body' => 'Prices dropped.', 'url' => 'https://sweegly.test/news']));

        $this->assertStringContainsString('Big news', $email['html']);
        $this->assertStringContainsString('data-unsubscribe', $email['html']);
        $this->assertStringContainsString('Unsubscribe', $email['text']);
    }

    public function test_unknown_types_and_purposes_fall_back_to_the_default(): void
    {
        $templates = app(MessageTemplates::class);

        $this->assertSame(['html' => 'messages.default', 'text' => 'messages.default-text'], $templates->for($this->message(Message::TYPE_SYSTEM_EMAIL, 'password_reset', [])));
        $this->assertSame(['html' => 'messages.default', 'text' => 'messages.default-text'], $templates->for($this->message('push_note', null, [])));
        $this->assertSame('messages.marketing_email.default', $templates->for($this->message(Message::TYPE_MARKETING_EMAIL, 'weekly', []))['html']);
        $this->assertSame('messages.default', $templates->for($this->message(Message::TYPE_SYSTEM_EMAIL, '../../etc', []))['html']);

        $email = $this->render($this->message(Message::TYPE_SYSTEM_EMAIL, 'password_reset', ['body' => 'Plain body', 'url' => 'https://app.test/reset']));
        $this->assertStringContainsString('Plain body', $email['html']);
        $this->assertStringContainsString('href="https://app.test/reset"', $email['html']);
    }

    public function test_payload_values_are_escaped_and_unsafe_links_dropped(): void
    {
        $email = $this->render($this->message(Message::TYPE_SYSTEM_EMAIL, Message::PURPOSE_INVITATION, [
            'organization_name' => '<img src=x onerror=alert(1)>', 'role' => '"><script>', 'invitation_url' => 'javascript:alert(1)',
        ]));

        $this->assertStringNotContainsString('<img src=x', $email['html']);
        $this->assertStringNotContainsString('<script>', $email['html']);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $email['html']);
        $this->assertStringNotContainsString('javascript:', $email['html']);
        $this->assertStringNotContainsString('javascript:', $email['text']);
    }

    public function test_html_templates_never_print_raw_values(): void
    {
        foreach (File::allFiles(resource_path('views/messages')) as $file) {
            if (! str_ends_with($file->getFilename(), '-text.blade.php')) {
                $this->assertStringNotContainsString('{!!', $file->getContents(), $file->getRelativePathname());
            }
        }
    }

    public function test_dev_and_stage_show_a_banner_and_the_subject_prefix_comes_only_from_the_worker(): void
    {
        config(['services.messages.environment' => 'stage']);

        $email = $this->render($this->message(Message::TYPE_SYSTEM_EMAIL, null, ['body' => 'Hi']));

        $this->assertSame('[STAGE] Hello', $email['subject']);
        $this->assertStringContainsString('STAGE environment: not a production email', $email['html']);
        $this->assertStringContainsString('[STAGE environment: not a production email]', $email['text']);
        $this->assertSame(1, substr_count($email['html'], '[STAGE]'));
    }

    public function test_preview_command_writes_html_and_text_without_sending(): void
    {
        $directory = sys_get_temp_dir().'/pane-preview-'.bin2hex(random_bytes(4));

        $this->artisan('messages:preview', ['type' => 'system_email', 'purpose' => 'invitation', '--output' => $directory])
            ->expectsOutputToContain('Wrote')->assertSuccessful();

        $this->assertStringContainsString('Accept the invitation', (string) file_get_contents("$directory/system_email-invitation.html"));
        $this->assertFileExists("$directory/system_email-invitation.txt");
        File::deleteDirectory($directory);

        $this->artisan('messages:preview', ['type' => 'sms'])->assertFailed();
    }
}
