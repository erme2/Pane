<?php

namespace App\Support\Maps;

use App\Exceptions\ValidationException;

/**
 * Named queries of a table map (Pane#225): a query is described once in the map
 * (filters, sort, join, typed parameters) and a client calls it by name with
 * values for its parameters. A query only ever becomes the same filter, sort and
 * join the client query language already accepts, so the access rule of the
 * table, per row, applies exactly as on a plain list request.
 */
class MapQueryRules
{
    public const array PARAM_TYPES = ['string', 'integer', 'boolean', 'date', 'record'];

    public const array OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'contains'];

    private const string NAME = '/\A[a-z][a-z0-9_]{0,63}\z/';

    /** A field of the table, or `field.column` of a table joined through it. */
    private const string FIELD = '/\A[a-z][a-z0-9_]{0,63}(\.[a-z][a-z0-9_]{0,63})?\z/';

    private const int SORT_MAX_FIELDS = 2;

    private const int JOIN_MAX = 3;

    /** The request keys a caller may add to a named query; everything else is the query's own. */
    private const array PASS_THROUGH = ['limit', 'page', 'order', 'count', 'params'];

    /**
     * Checks the queries of a table map and returns them normalized, or null for none.
     *
     * @param  array<string, string>  $errors
     * @return list<array<string, mixed>>|null
     */
    public static function normalize(mixed $queries, mixed $fields, array &$errors): ?array
    {
        if ($queries === null || $queries === []) {
            return null;
        }
        if (! is_array($queries) || ! array_is_list($queries)) {
            $errors['queries'] = 'Must be a list of queries or null.';

            return null;
        }
        $columns = self::columns($fields);

        $normalized = [];
        $seen = [];
        foreach ($queries as $i => $query) {
            $prefix = "queries.$i";
            if (! is_array($query)) {
                $errors[$prefix] = 'Must be an object.';

                continue;
            }
            $name = $query['name'] ?? null;
            if (! is_string($name) || ! preg_match(self::NAME, $name)) {
                $errors["$prefix.name"] = 'Use 1-64 lowercase letters, digits or underscores, starting with a letter.';
            } elseif (isset($seen[$name])) {
                $errors["$prefix.name"] = 'Duplicate query name.';
            } else {
                $seen[$name] = true;
            }
            if (array_diff(array_keys($query), ['name', 'description', 'params', 'filter', 'sort', 'join']) !== []) {
                $errors[$prefix] = 'Only name, description, params, filter, sort and join are allowed.';
            }
            if (isset($query['description']) && ! is_string($query['description'])) {
                $errors["$prefix.description"] = 'Must be a string or null.';
            }

            $params = self::normalizeParams($query['params'] ?? [], $prefix, $errors);
            $join = self::normalizeJoin($query['join'] ?? null, $columns, $prefix, $errors);
            $joined = $join === null ? [] : explode(',', $join);
            $filter = self::normalizeFilter($query['filter'] ?? [], $params, $columns, $joined, $prefix, $errors);
            $sort = self::normalizeSort($query['sort'] ?? null, $columns, $joined, $prefix, $errors);

            $normalized[] = [
                'name' => $name,
                'description' => $query['description'] ?? null,
                'params' => $params,
                'filter' => $filter,
                'sort' => $sort,
                'join' => $join,
            ];
        }

        return $normalized;
    }

    /**
     * What the list reader allows per field of the table: a password is never readable, only an
     * indexed, primary, sortable or unique field sorts, and only a field with an `exists` rule joins.
     *
     * @return array<string, array{password: bool, sortable: bool, joinable: bool}>
     */
    private static function columns(mixed $fields): array
    {
        $columns = [];
        foreach (is_array($fields) ? array_filter($fields, 'is_array') : [] as $field) {
            if (! is_string($field['name'] ?? null)) {
                continue;
            }
            $validations = is_array($field['validations'] ?? null) ? array_filter($field['validations'], 'is_array') : [];
            $rules = array_column($validations, 'rule');
            $joinable = false;
            foreach ($validations as $validation) {
                $joinable = $joinable || (($validation['rule'] ?? null) === 'exists' && is_string($validation['value'] ?? null));
            }
            $columns[$field['name']] = [
                'password' => ($field['type'] ?? null) === 'password',
                'sortable' => ! empty($field['index']) || ! empty($field['primary']) || ! empty($field['sortable']) || in_array('unique', $rules, true),
                'joinable' => $joinable,
            ];
        }

        return $columns;
    }

    /**
     * @param  array<string, string>  $errors
     * @return list<array{name: string, type: string, default?: string}>
     */
    private static function normalizeParams(mixed $params, string $prefix, array &$errors): array
    {
        if (! is_array($params) || ! array_is_list($params)) {
            $errors["$prefix.params"] = 'Must be a list.';

            return [];
        }
        $normalized = [];
        $seen = [];
        foreach ($params as $j => $param) {
            $where = "$prefix.params.$j";
            if (! is_array($param)) {
                $errors[$where] = 'Must be an object.';

                continue;
            }
            if (array_diff(array_keys($param), ['name', 'type', 'default']) !== []) {
                $errors[$where] = 'Only name, type and default are allowed.';
            }
            $name = $param['name'] ?? null;
            if (! is_string($name) || ! preg_match(self::NAME, $name)) {
                $errors["$where.name"] = 'Use 1-64 lowercase letters, digits or underscores, starting with a letter.';

                continue;
            }
            if (isset($seen[$name])) {
                $errors["$where.name"] = 'Duplicate parameter name.';

                continue;
            }
            $seen[$name] = true;
            $type = $param['type'] ?? null;
            if (! in_array($type, self::PARAM_TYPES, true)) {
                $errors["$where.type"] = 'One of: '.implode(', ', self::PARAM_TYPES).'.';

                continue;
            }
            $entry = ['name' => $name, 'type' => $type];
            if (array_key_exists('default', $param) && $param['default'] !== null) {
                $default = self::cast($type, $param['default']);
                if ($default === null) {
                    $errors["$where.default"] = "Must be a valid $type value.";
                } else {
                    $entry['default'] = $default;
                }
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param  list<array{name: string, type: string, default?: string}>  $params
     * @param  array<string, array{password: bool, sortable: bool, joinable: bool}>  $columns
     * @param  list<string>  $joined
     * @param  array<string, string>  $errors
     * @return list<array{field: string, op: string, value?: string|int|float|bool, param?: string}>
     */
    private static function normalizeFilter(mixed $filter, array $params, array $columns, array $joined, string $prefix, array &$errors): array
    {
        if (! is_array($filter) || ! array_is_list($filter)) {
            $errors["$prefix.filter"] = 'Must be a list.';

            return [];
        }
        $declared = array_column($params, 'name');
        $normalized = [];
        $seen = [];
        foreach ($filter as $k => $condition) {
            $where = "$prefix.filter.$k";
            if (! is_array($condition) || array_diff(array_keys($condition), ['field', 'op', 'value', 'param']) !== []) {
                $errors[$where] = 'Must be an object with field, op and one of value or param.';

                continue;
            }
            $field = $condition['field'] ?? null;
            if (! is_string($field) || ! self::usable($field, $columns, $joined, false)) {
                $errors["$where.field"] = 'Must be a readable field of this table, or field.column of a table joined by this query.';

                continue;
            }
            $op = $condition['op'] ?? 'eq';
            if (! in_array($op, self::OPERATORS, true)) {
                $errors["$where.op"] = 'One of: '.implode(', ', self::OPERATORS).'.';

                continue;
            }
            if (isset($seen["$field|$op"])) {
                $errors[$where] = 'Only one condition per field and operator.';

                continue;
            }
            $seen["$field|$op"] = true;
            if (array_key_exists('param', $condition) === array_key_exists('value', $condition)) {
                $errors[$where] = 'Give exactly one of value or param.';

                continue;
            }
            if (array_key_exists('param', $condition)) {
                if (! in_array($condition['param'], $declared, true)) {
                    $errors["$where.param"] = 'Must be one of the query parameters.';

                    continue;
                }
                $normalized[] = ['field' => $field, 'op' => $op, 'param' => $condition['param']];
            } else {
                if (! is_scalar($condition['value'])) {
                    $errors["$where.value"] = 'Must be a single value.';

                    continue;
                }
                $normalized[] = ['field' => $field, 'op' => $op, 'value' => $condition['value']];
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, array{password: bool, sortable: bool, joinable: bool}>  $columns
     * @param  list<string>  $joined
     * @param  array<string, string>  $errors
     */
    private static function normalizeSort(mixed $sort, array $columns, array $joined, string $prefix, array &$errors): ?string
    {
        if ($sort === null) {
            return null;
        }
        $parts = is_string($sort) ? array_filter(explode(',', $sort), fn (string $part): bool => $part !== '') : [];
        $valid = $parts !== [] && count($parts) <= self::SORT_MAX_FIELDS;
        foreach ($parts as $part) {
            $field = ltrim($part, '-');
            $valid = $valid && self::usable($field, $columns, $joined, true);
        }
        if (! $valid) {
            $errors["$prefix.sort"] = 'Must be "field" or "field,field" of sortable fields (at most '.self::SORT_MAX_FIELDS.', a "-" prefix sorts descending).';

            return null;
        }

        return (string) $sort;
    }

    /**
     * @param  array<string, array{password: bool, sortable: bool, joinable: bool}>  $columns
     * @param  array<string, string>  $errors
     */
    private static function normalizeJoin(mixed $join, array $columns, string $prefix, array &$errors): ?string
    {
        if ($join === null) {
            return null;
        }
        $parts = is_string($join) ? explode(',', $join) : [];
        $valid = $parts !== [] && count($parts) <= self::JOIN_MAX && count(array_unique($parts)) === count($parts);
        foreach ($parts as $part) {
            $valid = $valid && ($columns[$part]['joinable'] ?? false) && ! $columns[$part]['password'];
        }
        if (! $valid) {
            $errors["$prefix.join"] = 'Must be "field" or "field,field" of fields of this table that reference another table (at most '.self::JOIN_MAX.').';

            return null;
        }

        return (string) $join;
    }

    /**
     * Whether the list reader accepts the field: a readable (and, to sort, sortable) field of the
     * table, or `join.column` of a table this query joins. The column of a joined table is only
     * known when the query runs.
     *
     * @param  array<string, array{password: bool, sortable: bool, joinable: bool}>  $columns
     * @param  list<string>  $joined
     */
    private static function usable(string $field, array $columns, array $joined, bool $sorting): bool
    {
        if (preg_match(self::FIELD, $field) !== 1) {
            return false;
        }
        if (str_contains($field, '.')) {
            return in_array(explode('.', $field)[0], $joined, true);
        }
        $column = $columns[$field] ?? null;

        return $column !== null && ! $column['password'] && (! $sorting || $column['sortable']);
    }

    /**
     * The list request a named query stands for: the query's own filter, sort and
     * join with the caller's parameters bound in, plus the caller's paging. Any
     * other key of the request is dropped, so a caller cannot widen the query.
     *
     * @param  array<string, mixed>  $query  one normalized query
     * @param  array<string, mixed>  $request  the caller's request data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function bind(array $query, array $request): array
    {
        $values = self::bindParams($query['params'], $request['params'] ?? []);

        $data = array_intersect_key($request, array_flip(self::PASS_THROUGH));
        unset($data['params']);
        foreach ($query['filter'] as $condition) {
            $data['filter'][$condition['field']][$condition['op']] = array_key_exists('param', $condition)
                ? $values[$condition['param']]
                : $condition['value'];
        }
        if ($query['sort'] !== null) {
            $data['sort'] = $query['sort'];
        }
        if ($query['join'] !== null) {
            $data['join'] = $query['join'];
        }

        return $data;
    }

    /**
     * @param  list<array{name: string, type: string, default?: string}>  $declared
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private static function bindParams(array $declared, mixed $given): array
    {
        if (! is_array($given)) {
            throw new ValidationException(['params' => ['The parameters must be params[name]=value']]);
        }
        $errors = [];
        $names = array_column($declared, 'name');
        foreach (array_diff(array_keys($given), $names) as $unknown) {
            $errors["params.$unknown"] = ['Unknown parameter'];
        }
        $values = [];
        foreach ($declared as $param) {
            $name = $param['name'];
            if (! array_key_exists($name, $given)) {
                if (array_key_exists('default', $param)) {
                    $values[$name] = $param['default'];
                } else {
                    $errors["params.$name"] = ['The parameter is required'];
                }

                continue;
            }
            $value = self::cast($param['type'], $given[$name]);
            if ($value === null) {
                $errors["params.$name"] = ["The parameter must be a valid {$param['type']}"];
            } else {
                $values[$name] = $value;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $values;
    }

    /**
     * The value as the string a filter takes, or null when it is not a valid value of the type.
     * Values are only ever bound to the query, never put into SQL.
     */
    public static function cast(string $type, mixed $value): ?string
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = (string) $value;

        return match ($type) {
            'string' => strlen($value) <= 255 ? $value : null,
            'integer' => preg_match('/\A-?\d{1,18}\z/', $value) === 1 ? $value : null,
            'boolean' => match ($value) {
                '1', 'true' => '1',
                '0', 'false' => '0',
                default => null,
            },
            'date' => preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null,
            'record' => preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $value) === 1 ? $value : null,
            default => null,
        };
    }
}
