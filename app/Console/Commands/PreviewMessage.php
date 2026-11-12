<?php

namespace App\Console\Commands;

use App\Mail\QueuedMessageMail;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PreviewMessage extends Command
{
    protected $signature = 'messages:preview
        {type=system_email : Message type}
        {purpose? : Purpose inside the type, e.g. invitation}
        {--output= : Directory to write to (default storage/app/message-previews)}';

    protected $description = 'Render an email template with sample data to HTML and text files, without sending anything.';

    public function handle(): int
    {
        $type = (string) $this->argument('type');
        $purpose = $this->argument('purpose');
        $purpose = is_string($purpose) && $purpose !== '' ? $purpose : null;

        if (! in_array($type, Message::TYPES, true)) {
            $this->error('Unknown type. Use one of: '.implode(', ', Message::TYPES));

            return self::FAILURE;
        }

        $message = new Message([
            'type' => $type,
            'purpose' => $purpose,
            'channel' => Message::CHANNEL_EMAIL,
            'recipient' => 'preview@example.com',
            'subject' => 'Preview: '.$type.($purpose !== null ? " / $purpose" : ''),
            'payload' => [
                'invitation_url' => 'https://app.example.com/auth/login?invitation_token=preview&redirect_to=https%3A%2F%2Fapp.example.com',
                'organization_name' => 'Example Organization',
                'role' => 'user',
                'expires_at' => now()->addDays(7)->toJSON(),
                'headline' => 'A headline',
                'body' => 'Some body text for the preview.',
                'url' => 'https://example.com',
            ],
        ]);

        $mail = (new QueuedMessageMail($message))->build();
        $directory = (string) ($this->option('output') ?: storage_path('app/message-previews'));
        File::ensureDirectoryExists($directory);
        $base = $directory.'/'.$type.($purpose !== null ? "-$purpose" : '');
        File::put("$base.html", $mail->render());
        File::put("$base.txt", (string) view($mail->textView, $mail->buildViewData())->render());

        $this->line("Subject: {$mail->subject}");
        $this->line("Wrote $base.html and $base.txt");

        return self::SUCCESS;
    }
}
