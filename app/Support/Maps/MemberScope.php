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
        /** Members column that holds the role (Pane#195), or null when every member has full access. */
        private readonly ?string $membersRole = null,
        /** Role value that lets a member read only. */
        private readonly ?string $readValue = null,
        /** Role value that lets a member read and write. */
        private readonly ?string $editValue = null,
    ) {}

    /**
     * Narrows a members query to the rows that count: any member for a read, and
     * with a role rule only the members whose role allows it (an unknown role
     * value gives no access).
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    private function forRole($query, bool $write): void
    {
        if ($this->membersRole === null) {
            return;
        }
        $write
            ? $query->where($this->membersRole, $this->editValue)
            : $query->whereIn($this->membersRole, [$this->readValue, $this->editValue]);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function restrict(Builder $query, bool $write = false): Builder
    {
        return $query->whereIn(
            $this->keyField,
            function ($sub) use ($write): void {
                $sub->from($this->membersTable)->select($this->membersKey)->where($this->membersUser, $this->userUuid);
                $this->forRole($sub, $write);
            }
        );
    }

    /**
     * @param  bool  $write  true when the user needs rights to write, not just to read
     */
    public function isMemberOf(mixed $key, bool $write = false): bool
    {
        if (! is_scalar($key) || $key === '') {
            return false;
        }

        $query = DB::table($this->membersTable)
            ->where($this->membersKey, $key)
            ->where($this->membersUser, $this->userUuid);
        $this->forRole($query, $write);

        return $query->exists();
    }

    /**
     * Adds the signed-in user as a member of a row they just created.
     */
    public function addCreator(mixed $key): void
    {
        DB::table($this->membersTable)->insert([
            $this->membersKey => $key,
            $this->membersUser => $this->userUuid,
        ] + ($this->membersRole === null ? [] : [$this->membersRole => $this->editValue]));
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
        if (! $this->isMemberOf($data[$this->keyField] ?? null, true)) {
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
        if ((string) $data[$this->keyField] !== (string) $current && ! $this->isMemberOf($data[$this->keyField], true)) {
            throw $this->forbidden('You are not a member of that row');
        }
    }

    public function forbidden(string $reason = 'You are not a member of that row'): SystemException
    {
        return new SystemException($reason, Response::HTTP_FORBIDDEN);
    }
}
