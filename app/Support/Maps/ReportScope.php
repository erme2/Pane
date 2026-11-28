<?php

namespace App\Support\Maps;

use App\Exceptions\SystemException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The reports rule of one table for one signed-in user (Pane#245): a table where
 * members file reports about content of a list they belong to, and only
 * administrators read them.
 *
 * - Anyone signed in may create a row, for a list they are a member of, with exactly
 *   one target set and one of the allowed reasons. Pane sets the reporter from the session and the status to open;
 *   whatever the client sent for them is ignored.
 * - Only an administrator reads rows, and may update only the status (open or closed): on an update
 *   every other value sent is ignored and the stored one kept.
 * - Nobody deletes a row through the API.
 *
 * Every column name comes from a map checked when the request started and every
 * value goes through a parameter binding.
 */
final class ReportScope implements RowScope
{
    public const string OPEN = 'open';

    public const string CLOSED = 'closed';

    public bool $keyIsPrimary = false;

    /**
     * @param  string  $keyField  the reporter column (holds the reporter's user uuid)
     * @param  list<string>  $targets  columns of which exactly one is set on a report
     */
    public function __construct(
        public readonly string $keyField,
        private readonly string $listField,
        private readonly array $targets,
        private readonly string $statusField,
        private readonly string $membersTable,
        private readonly string $membersKey,
        private readonly string $membersUser,
        private readonly string $userUuid,
        private readonly bool $administrator,
        /** Column that holds the reason, and the reasons it may hold (maps have no "one of" rule yet). */
        private readonly string $reasonField,
        /** @var list<string> */
        private readonly array $reasons,
    ) {}

    /** Only an administrator reads reports. */
    public function mayRead(): bool
    {
        return $this->administrator;
    }

    /**
     * The reporter and the status are Pane's to set, not the client's.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withStamp(array $data): array
    {
        $data[$this->keyField] = $this->userUuid;
        $data[$this->statusField] = self::OPEN;

        return $data;
    }

    public function restrict(Builder $query, bool $write = false): Builder
    {
        return $this->administrator ? $query : $query->whereRaw('1 = 0');
    }

    public function assertCanCreate(array $data): void
    {
        $set = array_filter($this->targets, fn (string $target): bool => filled($data[$target] ?? null));
        if (count($set) !== 1) {
            throw new SystemException('A report needs exactly one target ('.implode(' or ', $this->targets).')', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (! in_array($data[$this->reasonField] ?? null, $this->reasons, true)) {
            throw new SystemException('The reason must be one of: '.implode(', ', $this->reasons), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $list = $data[$this->listField] ?? null;
        $isMember = is_scalar($list) && $list !== '' && DB::table($this->membersTable)
            ->where($this->membersKey, $list)
            ->where($this->membersUser, $this->userUuid)
            ->exists();
        if (! $isMember) {
            throw $this->forbidden('You are not a member of that list');
        }
    }

    public function assertCanUpdate(array $data, mixed $current): void
    {
        if (! $this->administrator) {
            throw $this->forbidden();
        }
        if (array_key_exists($this->statusField, $data) && ! in_array($data[$this->statusField], [self::OPEN, self::CLOSED], true)) {
            throw new SystemException('The status must be open or closed', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * An update is a full row (the map's required fields must be sent), but only the status counts:
     * every other value is the stored one.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $stored  the row as it is now
     * @return array<string, mixed>
     */
    public function onlyStatus(array $data, array $stored): array
    {
        return [$this->statusField => $data[$this->statusField] ?? $stored[$this->statusField] ?? null] + $stored;
    }

    /** A report is closed, never deleted, through the API. */
    public function assertCanDelete(): void
    {
        throw $this->forbidden('A report cannot be deleted');
    }

    public function forbidden(string $reason = ''): SystemException
    {
        return new SystemException($reason !== '' ? $reason : 'Reports are for administrators', Response::HTTP_FORBIDDEN);
    }
}
