<?php

namespace App\Support\Maps;

use App\Exceptions\SystemException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

/**
 * The owner rule of one table for one signed-in user (Pane#218): a row is
 * visible and writable only when its key column holds the user's uuid. It
 * applies to administrators too: nobody reads or writes another user's rows
 * through it. The column name comes from a checked map and the value goes
 * through a parameter binding.
 */
final class OwnerScope implements RowScope
{
    public bool $keyIsPrimary = false;

    public function __construct(
        public readonly string $keyField,
        private readonly string $userUuid,
    ) {}

    public function restrict(Builder $query, bool $write = false): Builder
    {
        return $query->where($this->keyField, $this->userUuid);
    }

    public function assertCanCreate(array $data): void
    {
        if (! $this->isOwn($data[$this->keyField] ?? null)) {
            throw $this->forbidden('A row must belong to you');
        }
    }

    public function assertCanUpdate(array $data, mixed $current): void
    {
        if (array_key_exists($this->keyField, $data) && ! $this->isOwn($data[$this->keyField])) {
            throw $this->forbidden('A row cannot be given to another user');
        }
    }

    public function forbidden(string $reason = ''): SystemException
    {
        return new SystemException($reason !== '' ? $reason : 'That row is not yours', Response::HTTP_FORBIDDEN);
    }

    private function isOwn(mixed $value): bool
    {
        return is_string($value) && hash_equals($this->userUuid, $value);
    }
}
