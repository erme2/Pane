<?php

namespace App\Support\Maps;

use App\Exceptions\SystemException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The members rule of one table for one signed-in user (Pane#185): a row is
 * visible and writable only when the user has a row in the members table for
 * the row's key. One hop; every value goes through parameter bindings and every
 * column name comes from a map that was checked when the request started.
 */
final class MemberScope
{
    public function __construct(
        /** Field of the table that holds the key (also its map field name). */
        public readonly string $keyField,
        /** True when the key is the table's own primary key (a parent table such as lists). */
        public readonly bool $keyIsPrimary,
        /** True when the members table has a rule of its own, so a creator row can be added. */
        public readonly bool $membersTableHasRule,
        private readonly string $membersTable,
        private readonly string $membersKey,
        private readonly string $membersUser,
        private readonly string $userUuid,
    ) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restrict(Builder $query): Builder
    {
        return $query->whereIn(
            $this->keyField,
            fn ($sub) => $sub->from($this->membersTable)->select($this->membersKey)->where($this->membersUser, $this->userUuid)
        );
    }

    public function isMemberOf(mixed $key): bool
    {
        if (! is_scalar($key) || $key === '') {
            return false;
        }

        return DB::table($this->membersTable)
            ->where($this->membersKey, $key)
            ->where($this->membersUser, $this->userUuid)
            ->exists();
    }

    /**
     * Adds the signed-in user as a member of a row they just created.
     */
    public function addCreator(mixed $key): void
    {
        DB::table($this->membersTable)->insert([
            $this->membersKey => $key,
            $this->membersUser => $this->userUuid,
        ]);
    }

    /**
     * What a member may send on create: a new row in a parent table (the creator
     * becomes its member), or a row for a key the user already belongs to.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SystemException
     */
    public function assertCanCreate(array $data): void
    {
        if ($this->keyIsPrimary) {
            if (! $this->membersTableHasRule) {
                throw $this->forbidden('The members table has no rule, so a creator row cannot be added');
            }

            return;
        }
        if (! $this->isMemberOf($data[$this->keyField] ?? null)) {
            throw $this->forbidden('You are not a member of that row');
        }
    }

    /**
     * Moving a row to another key is allowed only into a key the user belongs to.
     *
     * @param  array<string, mixed>  $data
     * @param  mixed  $current  the key value the row has now
     *
     * @throws SystemException
     */
    public function assertCanUpdate(array $data, mixed $current): void
    {
        if ($this->keyIsPrimary || ! array_key_exists($this->keyField, $data)) {
            return;
        }
        if ((string) $data[$this->keyField] !== (string) $current && ! $this->isMemberOf($data[$this->keyField])) {
            throw $this->forbidden('You are not a member of that row');
        }
    }

    public function forbidden(string $reason = 'You are not a member of that row'): SystemException
    {
        return new SystemException($reason, Response::HTTP_FORBIDDEN);
    }
}
