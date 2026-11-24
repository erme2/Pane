<?php

namespace App\Messages;

use App\Models\Message;
use Illuminate\Mail\Message as MailMessage;
use Illuminate\Support\Facades\Mail;

/** Sends the message as a plain-text email through the configured `MAIL_*` transport. */
class EmailChannel implements MessageChannel
{
    public const string NAME = 'email';

    public function send(Message $message): void
    {
        Mail::raw($message->body, function (MailMessage $mail) use ($message): void {
            $mail->to($message->recipient)->subject((string) $message->subject);
        });
    }
}
