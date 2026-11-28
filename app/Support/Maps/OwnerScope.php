<?php

namespace App\Support\Maps;

use App\Exceptions\SystemException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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
        /**
         * Optional checks on a new row (Pane#245): the list it is for must be one the user belongs to, and the
         * member it names must be somebody else. Columns and tables come from checked maps.
         *
         * @var array{list: string, table: string, key: string, user: string, other: string|null}|null
         */
        private readonly ?array $membership = null,
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
        if ($this->membership === null) {
            return;
        }
        $list = $data[$this->membership['list']] ?? null;
        $isMember = is_scalar($list) && $list !== '' && DB::table($this->membership['table'])
            ->where($this->membership['key'], $list)
            ->where($this->membership['user'], $this->userUuid)
            ->exists();
        if (! $isMember) {
            throw $this->forbidden('You are not a member of that list');
        }
        $other = $this->membership['other'];
        if ($other !== null && ($this->isOwn($data[$other] ?? null) || ! filled($data[$other] ?? null))) {
            throw new SystemException('Name another member', Response::HTTP_UNPROCESSABLE_ENTITY);
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
