<?php

namespace App\Services;

use App\Exceptions\SystemException;
use App\Models\Field;
use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\User;
use App\Support\Maps\MapFieldValidation;
use App\Support\Maps\MemberScope;
use App\Support\Maps\OwnerScope;
use App\Support\Maps\ReportScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;

/**
 * The new maps as the CRUD route reads them (Pane#185): the maps of the
 * install's own, non-system tables. System subjects (users, audit events, the
 * legacy map tables) are not here and keep the legacy `map_*` tables.
 */
class AppMaps
{
    public const string SOURCE = 'install';

    public function table(string $name): ?MapTable
    {
        $map = MapTable::query()
            ->whereHas('source', fn ($query) => $query->where('name', self::SOURCE))
            ->where('name', $name)
            ->first();

        return $map === null || $map->isSystem() ? null : $map;
    }

    /** The SQL name of the table a map describes. */
    public function sqlName(MapTable $map): string
    {
        return $map->sql_name ?? $map->name;
    }

    /**
     * The fields of a map as the CRUD mapper expects them.
     *
     * @return Collection<int, Field>
     *
     * @throws SystemException
     */
    public function fields(MapTable $map): Collection
    {
        return new Collection($map->fields()->get()->map(function (MapField $field) use ($map): Field {
            // The mapper reads and writes columns by field name; a renamed column isn't supported yet.
            if ($field->sql_name !== null && $field->sql_name !== $field->name) {
                throw new SystemException(
                    "Field {$map->name}.{$field->name} has the sql_name {$field->sql_name}, which the CRUD route doesn't support yet",
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }

            return (new Field)->forceFill([
                'field_id' => $field->map_field_id,
                'name' => $field->name,
                'sql_name' => $field->name,
                'primary' => $field->primary,
                'index' => $field->index,
                'sortable' => $field->sortable,
                'nullable' => $field->nullable,
                'default' => $field->default,
                'type' => $field->type,
            ])->withMapValidations(array_map(
                fn (array $validation): MapFieldValidation => new MapFieldValidation($validation),
                $field->validations ?? []
            ));
        })->all());
    }

    /**
     * The members rule of a subject for a user, or null when the subject has no rule.
     * Fails closed: a rule whose members table or columns the maps don't describe
     * answers 500 rather than falling back to open access.
     *
     * @throws SystemException
     */
    public function memberScope(string $subject, User $user): ?MemberScope
    {
        $map = $this->table($subject);
        $rule = $this->rule($subject, $map, 'members');
        if ($map === null || ! is_array($rule)) {
            return null;
        }

        $members = $this->table($rule['table'] ?? '');
        if ($members === null) {
            throw $this->misconfigured($subject, 'its members table is not mapped');
        }
        $key = $this->column($map, $rule['key'] ?? '');
        $membersKey = $this->column($members, $rule['members_key'] ?? '');
        $membersUser = $this->column($members, $rule['members_user'] ?? '');
        $roleRule = $rule['role'] ?? null;
        $membersRole = is_array($roleRule) ? $this->column($members, $roleRule['field'] ?? '') : null;
        if ($key === null || $membersKey === null || $membersUser === null || (is_array($roleRule) && $membersRole === null)) {
            throw $this->misconfigured($subject, 'a column of its rule is not in the maps');
        }
        if (! filled($user->uuid)) {
            throw new SystemException('The signed-in user has no uuid', Response::HTTP_FORBIDDEN);
        }

        return new MemberScope(
            keyField: $key->name,
            keyIsPrimary: $key->primary,
            membersTableHasRule: $this->hasRule($rule['table']),
            membersTable: $this->sqlName($members),
            membersKey: $membersKey->sql_name ?? $membersKey->name,
            membersUser: $membersUser->sql_name ?? $membersUser->name,
            userUuid: (string) $user->uuid,
            membersRole: $membersRole === null ? null : ($membersRole->sql_name ?? $membersRole->name),
            readValue: is_array($roleRule) ? (string) $roleRule['read'] : null,
            editValue: is_array($roleRule) ? (string) $roleRule['edit'] : null,
            creatorField: is_string($rule['creator'] ?? null) && $this->column($map, $rule['creator']) !== null ? $rule['creator'] : null,
            hideBlocked: $this->hideBlocked($subject, $map, $rule['hide_blocked'] ?? null),
        );
    }

    /**
     * The block rule of a members rule (Pane#245): `hide_blocked` names this table's author column and a
     * blocks table (`table`, `list`, `blocker`, `blocked`). Fails closed on a column the maps don't describe.
     *
     * @return array{author: string, table: string, list: string, blocker: string, blocked: string}|null
     *
     * @throws SystemException
     */
    private function hideBlocked(string $subject, MapTable $map, mixed $rule): ?array
    {
        if (! is_array($rule)) {
            return null;
        }
        $blocks = $this->table((string) ($rule['table'] ?? ''));
        $author = $this->column($map, (string) ($rule['author'] ?? ''));
        $list = $blocks === null ? null : $this->column($blocks, (string) ($rule['list'] ?? ''));
        $blocker = $blocks === null ? null : $this->column($blocks, (string) ($rule['blocker'] ?? ''));
        $blocked = $blocks === null ? null : $this->column($blocks, (string) ($rule['blocked'] ?? ''));
        if ($blocks === null || $author === null || $list === null || $blocker === null || $blocked === null) {
            throw $this->misconfigured($subject, 'its block rule is not in the maps');
        }

        return [
            'author' => $author->name,
            'table' => $this->sqlName($blocks),
            'list' => $list->name,
            'blocker' => $blocker->name,
            'blocked' => $blocked->name,
        ];
    }

    /**
     * The owner rule of a subject for a user (Pane#218), or null when the subject
     * has none. Fails closed on a key column the maps don't describe.
     *
     * @throws SystemException
     */
    public function ownerScope(string $subject, User $user): ?OwnerScope
    {
        $map = $this->table($subject);
        $rule = $this->rule($subject, $map, 'owner');
        if ($map === null || ! is_array($rule)) {
            return null;
        }

        $key = $this->column($map, $rule['key'] ?? '');
        if ($key === null) {
            throw new SystemException("The owner rule of $subject cannot be used: its key is not in the maps", Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        if (! filled($user->uuid)) {
            throw new SystemException('The signed-in user has no uuid', Response::HTTP_FORBIDDEN);
        }

        return new OwnerScope($key->name, (string) $user->uuid, $this->ownerMembership($subject, $map, $rule['member_of'] ?? null));
    }

    /**
     * The optional `member_of` of an owner rule (Pane#245): the new row names a list (`list`) the user belongs
     * to, found in a members table (`table`, `members_key`, `members_user`), and may name another member in
     * `other`. Fails closed on a column the maps don't describe.
     *
     * @return array{list: string, table: string, key: string, user: string, other: string|null}|null
     *
     * @throws SystemException
     */
    private function ownerMembership(string $subject, MapTable $map, mixed $rule): ?array
    {
        if (! is_array($rule)) {
            return null;
        }
        $members = $this->table((string) ($rule['table'] ?? ''));
        $list = $this->column($map, (string) ($rule['list'] ?? ''));
        $key = $members === null ? null : $this->column($members, (string) ($rule['members_key'] ?? ''));
        $user = $members === null ? null : $this->column($members, (string) ($rule['members_user'] ?? ''));
        $other = isset($rule['other']) ? $this->column($map, (string) $rule['other']) : null;
        if ($members === null || $list === null || $key === null || $user === null || (isset($rule['other']) && $other === null)) {
            throw new SystemException("The owner rule of $subject cannot be used: its member_of is not in the maps", Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return [
            'list' => $list->name,
            'table' => $this->sqlName($members),
            'key' => $key->sql_name ?? $key->name,
            'user' => $user->sql_name ?? $user->name,
            'other' => $other?->name,
        ];
    }

    /**
     * The reports rule of a subject for a user (Pane#245), or null when the subject has none. The rule names the
     * `reporter`, `list`, `status` and `reason` columns, the allowed `reasons`, the `targets` of which exactly one is set, and the `members`
     * table (`table`, `members_key`, `members_user`) that says who belongs to a list. Fails closed.
     *
     * @throws SystemException
     */
    public function reportScope(string $subject, User $user, bool $administrator): ?ReportScope
    {
        $map = $this->table($subject);
        $rule = $this->rule($subject, $map, 'reports');
        if ($map === null || ! is_array($rule)) {
            return null;
        }

        $members = $this->table((string) ($rule['members']['table'] ?? ''));
        $reporter = $this->column($map, (string) ($rule['reporter'] ?? ''));
        $list = $this->column($map, (string) ($rule['list'] ?? ''));
        $status = $this->column($map, (string) ($rule['status'] ?? ''));
        $targets = array_values(array_filter((array) ($rule['targets'] ?? []), 'is_string'));
        $membersKey = $members === null ? null : $this->column($members, (string) ($rule['members']['members_key'] ?? ''));
        $membersUser = $members === null ? null : $this->column($members, (string) ($rule['members']['members_user'] ?? ''));
        $reason = $this->column($map, (string) ($rule['reason'] ?? ''));
        $reasons = array_values(array_filter((array) ($rule['reasons'] ?? []), 'is_string'));
        $targetsMapped = $targets !== [] && collect($targets)->every(fn (string $target): bool => $this->column($map, $target) !== null);
        if ($members === null || $reporter === null || $list === null || $status === null
            || $membersKey === null || $membersUser === null || ! $targetsMapped || $reason === null || $reasons === []) {
            throw new SystemException("The reports rule of $subject cannot be used: a column of its rule is not in the maps", Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        if (! filled($user->uuid)) {
            throw new SystemException('The signed-in user has no uuid', Response::HTTP_FORBIDDEN);
        }

        return new ReportScope(
            keyField: $reporter->name,
            listField: $list->name,
            targets: $targets,
            statusField: $status->name,
            membersTable: $this->sqlName($members),
            membersKey: $membersKey->sql_name ?? $membersKey->name,
            membersUser: $membersUser->sql_name ?? $membersUser->name,
            userUuid: (string) $user->uuid,
            administrator: $administrator,
            reasonField: $reason->name,
            reasons: $reasons,
        );
    }

    /**
     * True when Pane's own rules give the subject the shared-read rule (Pane#232):
     * any signed-in user reads every row, only administrators write.
     */
    public function hasReadRule(string $subject): bool
    {
        return $this->table($subject) !== null && ($this->paneRules($subject)['read'] ?? false) === true;
    }

    /**
     * True when Pane or the table's map has an access rule for the subject.
     */
    public function hasRule(string $subject): bool
    {
        $map = $this->table($subject);
        if ($map === null) {
            return false;
        }

        return $this->hasReadRule($subject)
            || $this->rule($subject, $map, 'members') !== null
            || $this->rule($subject, $map, 'owner') !== null
            || $this->rule($subject, $map, 'reports') !== null;
    }

    /**
     * One rule kind of a subject. Pane's own rules (config/pane_access.php) win
     * over the map's `access`, which is honoured only until the maps drop it.
     *
     * @return array<string, mixed>|null
     */
    private function rule(string $subject, ?MapTable $map, string $kind): ?array
    {
        $own = $this->paneRules($subject);
        if ($own !== []) {
            return is_array($own[$kind] ?? null) ? $own[$kind] : null;
        }
        $rule = $map?->access[$kind] ?? null;

        return is_array($rule) ? $rule : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function paneRules(string $subject): array
    {
        $rules = config('pane_access.tables.'.$subject);

        return is_array($rules) ? $rules : [];
    }

    private function column(MapTable $map, string $name): ?MapField
    {
        return $map->fields()->where('name', $name)->first();
    }

    private function misconfigured(string $subject, string $why): SystemException
    {
        return new SystemException("The members rule of $subject cannot be used: $why", Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
