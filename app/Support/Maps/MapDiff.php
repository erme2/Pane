<?php

namespace App\Support\Maps;

/**
 * The difference between two table map snapshots, stored with each version so
 * the history reads without replaying snapshots.
 */
class MapDiff
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     * @return array{table: array<string, array{0: mixed, 1: mixed}>, added: list<string>, removed: list<string>, changed: array<string, array<string, array{0: mixed, 1: mixed}>>}
     */
    public static function tables(?array $before, array $after): array
    {
        $diff = ['table' => [], 'added' => [], 'removed' => [], 'changed' => []];

        foreach (['name', 'sql_name', 'description', 'ui'] as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old !== $new) {
                $diff['table'][$key] = [$old, $new];
            }
        }

        $oldFields = self::byName($before['fields'] ?? []);
        $newFields = self::byName($after['fields'] ?? []);

        foreach ($newFields as $name => $field) {
            if (! isset($oldFields[$name])) {
                $diff['added'][] = $name;

                continue;
            }
            foreach ($field as $key => $value) {
                $old = $oldFields[$name][$key] ?? null;
                if ($old !== $value) {
                    $diff['changed'][$name][$key] = [$old, $value];
                }
            }
        }
        foreach (array_keys($oldFields) as $name) {
            if (! isset($newFields[$name])) {
                $diff['removed'][] = (string) $name;
            }
        }

        return $diff;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function flat(?array $before, array $after): array
    {
        $diff = [];
        foreach (array_unique([...array_keys($before ?? []), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old !== $new) {
                $diff[$key] = [$old, $new];
            }
        }

        return $diff;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function byName(mixed $fields): array
    {
        $byName = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (is_array($field) && isset($field['name']) && is_string($field['name'])) {
                $byName[$field['name']] = $field;
            }
        }

        return $byName;
    }
}
