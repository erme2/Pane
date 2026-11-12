<?php

namespace App\Support\Messages;

use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;

/**
 * Finds the email templates for a message and builds their data.
 *
 * Lookup, first match wins (HTML view, plus the same name with `-text`):
 * `messages.{type}.{purpose}`, `messages.{type}.default`, `messages.default`.
 * Templates live in `resources/views/messages`; the HTML ones escape every
 * value, and only http(s) links are offered as links.
 */
final class MessageTemplates
{
    /**
     * @return array{html: string, text: string}
     */
    public function for(Message $message): array
    {
        $candidates = array_filter([
            $message->purpose !== null ? "messages.{$message->type}.{$message->purpose}" : null,
            "messages.{$message->type}.default",
            'messages.default',
        ]);

        foreach ($candidates as $view) {
            if ($this->isSafeName($view) && View::exists($view) && View::exists("$view-text")) {
                return ['html' => $view, 'text' => "$view-text"];
            }
        }

        return ['html' => 'messages.default', 'text' => 'messages.default-text'];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Message $message, string $subject): array
    {
        $payload = array_filter($message->payload, fn (mixed $value): bool => is_scalar($value) || $value === null);
        $link = $payload['invitation_url'] ?? $payload['url'] ?? null;
        $expires = is_string($payload['expires_at'] ?? null) ? strtotime($payload['expires_at']) : false;
        $label = SubjectPrefix::for(SubjectPrefix::environment());

        return [
            'payload' => array_map(fn (mixed $value): string => (string) $value, $payload),
            'subject' => $subject,
            'link' => is_string($link) && preg_match('~\Ahttps?://~i', $link) === 1 ? $link : null,
            'expiresAt' => $expires !== false ? Carbon::createFromTimestamp($expires)->utc()->format('j F Y, H:i').' UTC' : null,
            'environmentLabel' => $label !== null ? trim($label, '[]') : null,
            'senderName' => (string) (config('mail.from.name') ?: config('app.name', 'Pane')),
        ];
    }

    private function isSafeName(string $view): bool
    {
        return preg_match('/\A[a-z0-9_.-]+\z/', $view) === 1;
    }
}
