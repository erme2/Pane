<?php

namespace App\Services;

use App\Models\Message;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Adds outgoing messages to the `messages` queue. Nothing is sent here: the
 * worker (`messages:send`) picks pending rows up later. Call it inside the
 * same transaction as the change that causes the message, so a rolled-back
 * change never leaves a message behind.
 */
class MessageQueue
{
    public function push(
        string $channel,
        string $recipient,
        ?string $subject,
        #[\SensitiveParameter] string $body,
        ?Model $source = null,
        ?DateTimeInterface $availableAt = null,
    ): Message {
        if (! array_key_exists($channel, (array) config('pane.messages.channels'))) {
            throw new InvalidArgumentException("Unsupported message channel [$channel].");
        }

        if (trim($recipient) === '') {
            throw new InvalidArgumentException('Message recipient is required.');
        }

        return Message::query()->create([
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'status' => Message::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => $availableAt ?? now(),
            'source_type' => $source === null ? null : class_basename($source),
            'source_id' => $source === null ? null : (string) $source->getKey(),
        ]);
    }
}
