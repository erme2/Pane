<?php

namespace App\Support\Maps;

use App\Exceptions\Maps\InvalidMapException;

/**
 * The closed lists and shape rules for a table map (Pane#153, answer 4): the
 * legacy field types and validation rules, stored by name. New types such as
 * datetime, decimal, enum and file come with the tickets that need them.
 */
class MapRules
{
    public const array FIELD_TYPES = ['number', 'string', 'text', 'boolean', 'date', 'array', 'password', 'json'];

    public const array VALIDATION_RULES = ['unique', 'exists', 'max', 'min', 'email', 'screened'];

    public const array SOURCE_KINDS = ['install', 'external'];

    private const string NAME = '/\A[a-z][a-z0-9_]{0,63}\z/';

    /**
     * Checks a table map and returns it normalized (every key present, fields
     * numbered by position in the order given).
     *
     * @param  array<string, mixed>  $map
     * @return array{name: string, sql_name: string|null, description: string|null, ui: array<mixed>|null, access: array<mixed>|null, fields: list<array<string, mixed>>}
     *
     * @throws InvalidMapException
     */
    public static function normalizeTable(array $map): array
    {
        $errors = [];

        $name = $map['name'] ?? null;
        if (! is_string($name) || ! preg_match(self::NAME, $name)) {
            $errors['name'] = 'Use 1-64 lowercase letters, digits or underscores, starting with a letter.';
        }
        foreach (['sql_name', 'description'] as $key) {
            if (isset($map[$key]) && ! is_string($map[$key])) {
                $errors[$key] = 'Must be a string or null.';
            }
        }
        if (isset($map['sql_name']) && is_string($map['sql_name']) && strlen($map['sql_name']) > 255) {
            $errors['sql_name'] = 'At most 255 characters.';
        }
        if (isset($map['ui']) && ! is_array($map['ui'])) {
            $errors['ui'] = 'Must be an object or null.';
        }

        $access = self::normalizeAccess($map['access'] ?? null, $map['fields'] ?? [], $errors);

        $fields = $map['fields'] ?? [];
        if (! is_array($fields) || ! array_is_list($fields)) {
            $errors['fields'] = 'Must be a list.';
            $fields = [];
        }

        $normalized = [];
        $seen = [];
        foreach ($fields as $i => $field) {
            $prefix = "fields.$i";
            if (! is_array($field)) {
                $errors[$prefix] = 'Must be an object.';

                continue;
            }
            $fieldName = $field['name'] ?? null;
            if (! is_string($fieldName) || ! preg_match(self::NAME, $fieldName)) {
                $errors["$prefix.name"] = 'Use 1-64 lowercase letters, digits or underscores, starting with a letter.';
            } elseif (isset($seen[$fieldName])) {
                $errors["$prefix.name"] = 'Duplicate field name.';
            } else {
                $seen[$fieldName] = true;
            }
            if (! in_array($field['type'] ?? null, self::FIELD_TYPES, true)) {
                $errors["$prefix.type"] = 'One of: '.implode(', ', self::FIELD_TYPES).'.';
            }
            foreach (['sql_name', 'description', 'format'] as $key) {
                if (isset($field[$key]) && ! is_string($field[$key])) {
                    $errors["$prefix.$key"] = 'Must be a string or null.';
                }
            }
            foreach (['primary', 'index', 'sortable', 'nullable'] as $key) {
                if (isset($field[$key]) && ! is_bool($field[$key])) {
                    $errors["$prefix.$key"] = 'Must be true or false.';
                }
            }
            foreach (['computed', 'ui'] as $key) {
                if (isset($field[$key]) && ! is_array($field[$key])) {
                    $errors["$prefix.$key"] = 'Must be an object or null.';
                }
            }

            $validations = $field['validations'] ?? [];
            if (! is_array($validations) || ! array_is_list($validations)) {
                $errors["$prefix.validations"] = 'Must be a list.';
                $validations = [];
            }
            foreach ($validations as $j => $validation) {
                if (! is_array($validation) || ! in_array($validation['rule'] ?? null, self::VALIDATION_RULES, true)) {
                    $errors["$prefix.validations.$j.rule"] = 'One of: '.implode(', ', self::VALIDATION_RULES).'.';

                    continue;
                }
                if (isset($validation['value']) && ! is_scalar($validation['value'])) {
                    $errors["$prefix.validations.$j.value"] = 'Must be a scalar or null.';
                }
                if (isset($validation['message']) && ! is_string($validation['message'])) {
                    $errors["$prefix.validations.$j.message"] = 'Must be a string or null.';
                }
            }

            $normalized[] = [
                'name' => $fieldName,
                'sql_name' => $field['sql_name'] ?? null,
                'type' => $field['type'] ?? null,
                'description' => $field['description'] ?? null,
                'format' => $field['format'] ?? null,
                'primary' => (bool) ($field['primary'] ?? false),
                'index' => (bool) ($field['index'] ?? false),
                'sortable' => (bool) ($field['sortable'] ?? false),
                'nullable' => (bool) ($field['nullable'] ?? false),
                'default' => $field['default'] ?? null,
                'validations' => array_values(array_map(fn ($v) => array_filter([
                    'rule' => $v['rule'] ?? null,
                    'value' => $v['value'] ?? null,
                    'message' => $v['message'] ?? null,
                ], fn ($value) => $value !== null), array_filter($validations, 'is_array'))),
                'computed' => $field['computed'] ?? null,
                'ui' => $field['ui'] ?? null,
                'position' => $i,
            ];
        }

        if ($errors !== []) {
            throw new InvalidMapException($errors);
        }

        /** @var string $name */
        return [
            'name' => $name,
            'sql_name' => $map['sql_name'] ?? null,
            'description' => $map['description'] ?? null,
            'ui' => $map['ui'] ?? null,
            'access' => $access,
            'fields' => $normalized,
        ];
    }

    /**
     * The members rule (Pane#185): a "members" object with the members `table`,
     * the `key` field of this table, and the `members_key` and `members_user`
     * fields of the members table. The members table's own map is checked where
     * all maps are known (AppInstaller) and again, fail-closed, on every request.
     *
     * @param  array<string, string>  $errors
     *                                         The owner rule (Pane#218) is the other kind: an "owner" object with the `key`
     *                                         field of this table, which holds the owner's user uuid.
     * @param  array<string, string>  $errors
     * @return array{members: array{table: string, key: string, members_key: string, members_user: string, role?: array{field: string, read: string, edit: string}, creator?: string}}|array{owner: array{key: string}}|null
     */
    private static function normalizeAccess(mixed $access, mixed $fields, array &$errors): ?array
    {
        if ($access === null) {
            return null;
        }
        if (is_array($access) && array_keys($access) === ['owner']) {
            return self::normalizeOwner($access['owner'], $fields, $errors);
        }
        $members = is_array($access) ? ($access['members'] ?? null) : null;
        if (! is_array($members) || array_diff(array_keys($access), ['members']) !== []) {
            $errors['access'] = 'Must be an object with one "members" or "owner" rule, or null.';

            return null;
        }

        $parts = ['table', 'key', 'members_key', 'members_user'];
        $rule = [];
        $role = null;
        if (array_key_exists('role', $members)) {
            $role = self::normalizeRole($members['role'], $errors);
        }
        foreach ($parts as $part) {
            if (! isset($members[$part]) || ! is_string($members[$part]) || ! preg_match(self::NAME, $members[$part])) {
                $errors["access.members.$part"] = 'Use 1-64 lowercase letters, digits or underscores, starting with a letter.';
            } else {
                $rule[$part] = $members[$part];
            }
        }
        if (array_diff(array_keys($members), [...$parts, 'role', 'creator']) !== []) {
            $errors['access.members'] = 'Only '.implode(', ', [...$parts, 'role', 'creator']).' are allowed.';
        }
        $names = array_column(is_array($fields) ? array_filter($fields, 'is_array') : [], 'name');
        $creator = null;
        if (array_key_exists('creator', $members)) {
            if (! is_string($members['creator']) || ! preg_match(self::NAME, $members['creator']) || ! in_array($members['creator'], $names, true)) {
                $errors['access.members.creator'] = 'Must be a field of this table.';
            } else {
                $creator = $members['creator'];
            }
        }
        if (isset($rule['key']) && ! in_array($rule['key'], $names, true)) {
            $errors['access.members.key'] = 'Must be a field of this table.';
        }

        if (count($rule) !== 4 || ($errors['access.members.role'] ?? null) !== null || ($errors['access.members.creator'] ?? null) !== null) {
            return null;
        }
        if ($creator !== null) {
            $rule['creator'] = $creator;
        }
        if ($role !== null) {
            $rule['role'] = $role;
        }

        return ['members' => $rule];
    }

    /**
     * @param  array<string, string>  $errors
     * @return array{owner: array{key: string}}|null
     */
    private static function normalizeOwner(mixed $owner, mixed $fields, array &$errors): ?array
    {
        if (! is_array($owner) || array_keys($owner) !== ['key']) {
            $errors['access.owner'] = 'Must be an object with a "key" field name only.';

            return null;
        }
        $key = $owner['key'];
        if (! is_string($key) || ! preg_match(self::NAME, $key)) {
            $errors['access.owner.key'] = 'Use 1-64 lowercase letters, digits or underscores, starting with a letter.';

            return null;
        }
        $names = array_column(is_array($fields) ? array_filter($fields, 'is_array') : [], 'name');
        if (! in_array($key, $names, true)) {
            $errors['access.owner.key'] = 'Must be a field of this table.';

            return null;
        }

        return ['owner' => ['key' => $key]];
    }

    /**
     * The optional role of a members rule (Pane#195): the members field that holds
     * the role and the two values that mean "read" and "edit". Any other value
     * gives no access.
     *
     * @param  array<string, string>  $errors
     * @return array{field: string, read: string, edit: string}|null
     */
    private static function normalizeRole(mixed $role, array &$errors): ?array
    {
        $valid = is_array($role) && array_keys($role) !== [] && array_diff(array_keys($role), ['field', 'read', 'edit']) === [];
        if ($valid) {
            foreach (['field', 'read', 'edit'] as $part) {
                $valid = $valid && isset($role[$part]) && is_string($role[$part]) && $role[$part] !== '' && strlen($role[$part]) <= 64;
            }
            $valid = $valid && preg_match(self::NAME, $role['field']) && $role['read'] !== $role['edit'];
        }
        if (! $valid) {
            $errors['access.members.role'] = 'Must be an object with a field name and different "read" and "edit" values (1-64 characters each).';

            return null;
        }

        return ['field' => $role['field'], 'read' => $role['read'], 'edit' => $role['edit']];
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array{name: string, kind: string, description: string|null}
     *
     * @throws InvalidMapException
     */
    public static function normalizeSource(array $map): array
    {
        $errors = [];
        $name = $map['name'] ?? null;
        if (! is_string($name) || ! preg_match(self::NAME, $name)) {
            $errors['name'] = 'Use 1-64 lowercase letters, digits or underscores, starting with a letter.';
        }
        if (! in_array($map['kind'] ?? null, self::SOURCE_KINDS, true)) {
            $errors['kind'] = 'One of: '.implode(', ', self::SOURCE_KINDS).'.';
        }
        if (isset($map['description']) && ! is_string($map['description'])) {
            $errors['description'] = 'Must be a string or null.';
        }
        if ($errors !== []) {
            throw new InvalidMapException($errors);
        }

        /** @var string $name */
        /** @var string $kind */
        $kind = $map['kind'];

        return ['name' => $name, 'kind' => $kind, 'description' => $map['description'] ?? null];
    }
}
