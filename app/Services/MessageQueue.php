<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Organization;
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
    /**
     * @param  array<string, mixed>  $payload  data for the template; may hold credentials, never log it
     */
    public function push(
        string $type,
        string $recipient,
        string $subject,
        #[\SensitiveParameter] array $payload,
        ?string $purpose = null,
        ?Organization $organization = null,
        ?Model $source = null,
        ?DateTimeInterface $availableAt = null,
    ): Message {
        if (! in_array($type, Message::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported message type [$type].");
        }

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Message recipient must be a valid email address.');
        }

        return Message::query()->create([
            'type' => $type,
            'purpose' => $purpose,
            'channel' => Message::CHANNEL_EMAIL,
            'recipient' => $recipient,
            'subject' => $subject,
            'payload' => $payload,
            'status' => Message::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => $availableAt ?? now(),
            'organization_id' => $organization?->getKey(),
            'source_type' => $source === null ? null : class_basename($source),
            'source_id' => $source === null ? null : (string) $source->getKey(),
        ]);
    }
}
