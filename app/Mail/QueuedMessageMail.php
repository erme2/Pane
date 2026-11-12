<?php

namespace App\Mail;

use App\Models\Message;
use App\Support\Messages\SubjectPrefix;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * The email for a queued message, with a plain built-in body. Per-type
 * templates replace the body later (#139). Every payload value is escaped.
 */
class QueuedMessageMail extends Mailable
{
    public function __construct(public readonly Message $queued) {}

    public function build(): self
    {
        [$lines, $link, $closing] = $this->parts();
        // Only web links become links; anything else (e.g. javascript:) is dropped.
        $link = $link !== null && preg_match('~\Ahttps?://~i', $link) === 1 ? $link : null;
        $text = implode("\n\n", array_filter([...$lines, $link, $closing]));
        $paragraph = fn (string $line): string => $line === '' ? '' : '<p>'.e($line).'</p>';
        $html = implode('', array_map($paragraph, $lines))
            .($link !== null ? '<p><a href="'.e($link).'">'.e($link).'</a></p>' : '')
            .$paragraph($closing);

        return $this
            ->subject(SubjectPrefix::apply($this->queued->subject))
            ->html($html)
            ->withSymfonyMessage(fn (Email $email) => $email->text($text));
    }

    /**
     * @return array{0: list<string>, 1: string|null, 2: string} lines before the link, the link, a closing line
     */
    private function parts(): array
    {
        $payload = $this->queued->payload;
        $value = fn (string $key): string => is_scalar($payload[$key] ?? null) ? (string) $payload[$key] : '';

        if ($this->queued->purpose === Message::PURPOSE_INVITATION) {
            $role = $value('role') !== '' ? " as {$value('role')}" : '';
            $expiresAt = strtotime($value('expires_at'));
            $expiry = $expiresAt !== false ? ' The invitation expires on '.gmdate('j F Y, H:i', $expiresAt).' UTC.' : '';

            return [[
                "You've been invited to join {$value('organization_name')}{$role}. Open this link to accept:",
            ], $value('invitation_url') ?: null, "If you weren't expecting this invitation, you can ignore this email.$expiry"];
        }

        return [$value('body') !== '' ? [$value('body')] : [], $value('url') ?: null, ''];
    }
}
