<?php

namespace App\Support\Maps;

use App\Exceptions\SystemException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What the CRUD actions need from a table's access rule for one signed-in
 * user: the rows they may reach, and what they may send on create and update.
 * Implemented by the members rule (Pane#185) and the owner rule (Pane#218).
 */
interface RowScope
{
    /** Field of the table the rule is keyed on (also its map field name). */
    public string $keyField { get; }

    /** True when the key is the table's own primary key (a parent table such as lists). */
    public bool $keyIsPrimary { get; }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  bool  $write  true when the user needs rights to write, not just to read
     * @return Builder<TModel>
     */
    public function restrict(Builder $query, bool $write = false): Builder;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws SystemException
     */
    public function assertCanCreate(array $data): void;

    /**
     * @param  array<string, mixed>  $data
     * @param  mixed  $current  the key value the row has now
     *
     * @throws SystemException
     */
    public function assertCanUpdate(array $data, mixed $current): void;

    public function forbidden(string $reason = ''): SystemException;
}
