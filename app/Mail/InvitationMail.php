<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitation email with the sign-up link. The same link works for an administrator or a user
 * invitation: the role is shown in the text, the sign-up flow is the same.
 */
class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $invitationUrl,
        public readonly string $role,
        public readonly string $inviterName,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You are invited to Sweegly');
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.invitation',
            with: [
                'invitationUrl' => $this->invitationUrl,
                'role' => $this->role,
                'inviterName' => $this->inviterName,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }
}
