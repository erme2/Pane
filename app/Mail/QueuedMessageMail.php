<?php

namespace App\Mail;

use App\Models\Message;
use App\Support\Messages\MessageTemplates;
use App\Support\Messages\SubjectPrefix;
use Illuminate\Mail\Mailable;

/**
 * The email for a queued message, rendered from its type's templates
 * (see MessageTemplates). The subject gets the environment prefix here,
 * once; templates never add it.
 */
class QueuedMessageMail extends Mailable
{
    public function __construct(public readonly Message $queued) {}

    public function build(): self
    {
        $templates = app(MessageTemplates::class);
        $subject = SubjectPrefix::apply($this->queued->subject);
        $views = $templates->for($this->queued);
        $data = $templates->data($this->queued, $subject);

        return $this->subject($subject)
            ->view($views['html'], $data)
            ->text($views['text'], $data);
    }
}
