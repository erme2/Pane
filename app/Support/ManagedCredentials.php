<?php

namespace App\Support;

use JsonSerializable;
use LogicException;
use Stringable;

/**
 * Decrypted managed-database credentials. Holds the plaintext only in memory:
 * dumps, string casts and JSON show a redacted placeholder, and serializing
 * (queued jobs, cache, sessions) is refused so plaintext can't be persisted
 * by accident. Read values explicitly with get() or reveal().
 */
final class ManagedCredentials implements JsonSerializable, Stringable
{
    private const string REDACTED = '[redacted]';

    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(#[\SensitiveParameter] private array $values) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function reveal(): array
    {
        return $this->values;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->values));
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return array_fill_keys($this->keys(), self::REDACTED);
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Managed credentials must not be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Managed credentials must not be unserialized.');
    }
}
