<?php

namespace App\Support\Maps;

/**
 * The personal-data rule of a table map (sweegly#49): the `owner` field holds the
 * person's user uuid, and `action` says what happens to their rows when the account
 * is anonymised:
 *
 * - `delete`: the person's rows are deleted;
 * - `soft_delete`: the rows are marked deleted in the `deleted_at` field, and so are the
 *   rows of each `cascade` table that point at them (`field` holds this row's primary key);
 * - `anonymise`: the rows stay and the owner becomes the fixed placeholder uuid.
 *
 * `clear` fields are blanked (null, or empty text) and `coarsen` date fields are cut
 * to the day, on the rows that stay. The names are checked here and again, fail-closed,
 * when the cleanup runs.
 */
class MapPersonalDataRules
{
    public const array ACTIONS = ['delete', 'soft_delete', 'anonymise'];

    private const string NAME = '/\A[a-z][a-z0-9_]{0,63}\z/';

    private const array KEYS = ['owner', 'action', 'deleted_at', 'clear', 'coarsen', 'cascade'];

    /**
     * @param  array<string, string>  $errors
     * @return array<string, mixed>|null
     */
    public static function normalize(mixed $rule, mixed $fields, array &$errors): ?array
    {
        if ($rule === null || $rule === []) {
            return null;
        }
        if (! is_array($rule) || array_is_list($rule) || array_diff(array_keys($rule), self::KEYS) !== []) {
            $errors['personal_data'] = 'Must be an object with only '.implode(', ', self::KEYS).', or null.';

            return null;
        }

        $byName = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (is_array($field) && is_string($field['name'] ?? null)) {
                $byName[$field['name']] = $field;
            }
        }

        $before = count($errors);
        $normalized = [];

        $owner = $rule['owner'] ?? null;
        if (! is_string($owner) || ! isset($byName[$owner])) {
            $errors['personal_data.owner'] = 'Must be a field of this table.';
        } else {
            $normalized['owner'] = $owner;
        }

        $action = $rule['action'] ?? null;
        if (! in_array($action, self::ACTIONS, true)) {
            $errors['personal_data.action'] = 'One of: '.implode(', ', self::ACTIONS).'.';
        } else {
            $normalized['action'] = $action;
        }

        if ($action === 'soft_delete') {
            $deletedAt = $rule['deleted_at'] ?? null;
            if (! is_string($deletedAt) || ! isset($byName[$deletedAt]) || ! self::isNullableDate($byName[$deletedAt])) {
                $errors['personal_data.deleted_at'] = 'Must be a nullable date field of this table.';
            } else {
                $normalized['deleted_at'] = $deletedAt;
            }
        } elseif (isset($rule['deleted_at']) || ! empty($rule['cascade'])) {
            $errors['personal_data.action'] = '"deleted_at" and "cascade" only go with "soft_delete".';
        }

        foreach (['clear', 'coarsen'] as $key) {
            $names = $rule[$key] ?? [];
            if (! is_array($names) || ! array_is_list($names)) {
                $errors["personal_data.$key"] = 'Must be a list of field names.';

                continue;
            }
            foreach ($names as $name) {
                $field = is_string($name) ? ($byName[$name] ?? null) : null;
                if ($field === null || $name === $owner || ($field['primary'] ?? false) === true) {
                    $errors["personal_data.$key"] = 'Must name fields of this table, other than the owner and the primary key.';
                } elseif ($key === 'clear' && ! self::canBeBlanked($field)) {
                    $errors["personal_data.$key"] = "\"$name\" must be nullable or a string or text field.";
                } elseif ($key === 'coarsen' && ($field['type'] ?? null) !== 'date') {
                    $errors["personal_data.$key"] = "\"$name\" must be a date field.";
                }
            }
            if ($names !== [] && $action === 'delete') {
                $errors["personal_data.$key"] = 'Rows that are deleted have nothing to clear or coarsen.';
            }
            if (($errors["personal_data.$key"] ?? null) === null && $names !== []) {
                $normalized[$key] = array_values(array_unique($names));
            }
        }

        $cascade = $rule['cascade'] ?? [];
        if (! is_array($cascade) || ! array_is_list($cascade)) {
            $errors['personal_data.cascade'] = 'Must be a list of table, field and deleted_at entries.';
        } else {
            $entries = [];
            foreach ($cascade as $entry) {
                $ok = is_array($entry) && array_keys($entry) === ['table', 'field', 'deleted_at'];
                foreach (['table', 'field', 'deleted_at'] as $part) {
                    $ok = $ok && is_string($entry[$part] ?? null) && preg_match(self::NAME, $entry[$part]) === 1;
                }
                if (! $ok) {
                    $errors['personal_data.cascade'] = 'Each entry needs a mapped table, its field that holds this row\'s key, and its date field "deleted_at".';

                    break;
                }
                $entries[] = ['table' => $entry['table'], 'field' => $entry['field'], 'deleted_at' => $entry['deleted_at']];
            }
            if ($entries !== [] && ($errors['personal_data.cascade'] ?? null) === null) {
                $normalized['cascade'] = $entries;
            }
        }

        return count($errors) > $before ? null : $normalized;
    }

    /** @param array<string, mixed> $field */
    private static function isNullableDate(array $field): bool
    {
        return ($field['type'] ?? null) === 'date' && ($field['nullable'] ?? false) === true;
    }

    /** @param array<string, mixed> $field */
    private static function canBeBlanked(array $field): bool
    {
        return ($field['nullable'] ?? false) === true || in_array($field['type'] ?? null, ['string', 'text'], true);
    }
}
