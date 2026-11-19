<?php

namespace App\Services;

use App\Exceptions\SystemException;
use App\Models\Field;
use App\Models\Maps\MapField;
use App\Models\Maps\MapTable;
use App\Models\User;
use App\Support\Maps\MapFieldValidation;
use App\Support\Maps\MemberScope;
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
        $rule = $map?->access['members'] ?? null;
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
        if ($key === null || $membersKey === null || $membersUser === null) {
            throw $this->misconfigured($subject, 'a column of its rule is not in the maps');
        }
        if (! filled($user->uuid)) {
            throw new SystemException('The signed-in user has no uuid', Response::HTTP_FORBIDDEN);
        }

        return new MemberScope(
            keyField: $key->name,
            keyIsPrimary: $key->primary,
            membersTableHasRule: $members->access !== null,
            membersTable: $this->sqlName($members),
            membersKey: $membersKey->sql_name ?? $membersKey->name,
            membersUser: $membersUser->sql_name ?? $membersUser->name,
            userUuid: (string) $user->uuid,
        );
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
