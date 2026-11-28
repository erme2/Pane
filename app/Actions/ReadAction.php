<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\PaginationHelper;
use App\Models\Maps\MapTable;
use App\Services\AppMaps;
use App\Stories\StoryPlot;
use App\Support\Maps\RowScope;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Class ReadAction
 * This action will read the data from the database and return it in the response
 */
class ReadAction extends AbstractAction
{
    use PaginationHelper;

    private const FILTER_OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'contains'];

    private const JOIN_MAX = 3;

    private const SEARCH_MAX_LENGTH = 200;

    private const SEARCH_TYPES = ['string', 'text'];

    private const SQL_OPERATORS = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

    /**
     * @throws SystemException
     * @throws ValidationException
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        $mapper = $this->getMapper($subject);
        $model = $this->getModel($subject);
        $keyName = $model->getPrimaryKey($subject);
        $scope = $this->memberScope($plot);

        // if we have a key, we are looking for a specific record
        if ($key) {
            // and we want to validate the key
            $errors = Validator::make(
                [$keyName => $key],
                $mapper->getValidationRules(false, true)
            )->errors();

            if ($errors->any()) {
                throw new ValidationException($errors->toArray());
            } else {
                try {
                    $record = $this->scopedQuery($model, $plot)->find($key);
                    if ($record) {
                        $plot->data[] = $mapper->extractFromModel($record);
                    } elseif ($scope !== null) {
                        // Same answer for a row that is missing and a row the user isn't a member of.
                        throw $scope->forbidden();
                    } else {
                        $plot->setStatus(Response::HTTP_NOT_FOUND);
                    }
                } catch (SystemException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    throw new SystemException($e->getMessage());
                }
            }
        } else {
            $joins = $this->getJoins($plot, $subject);
            $joinSortable = [];
            foreach ($joins as $join) {
                foreach ($join['sortable'] as $name) {
                    $joinSortable[] = $join['field'].'.'.$name;
                }
            }
            $pagination = $this->getPaginationData($plot, $subject, $joinSortable);
            $filters = $this->getFilters($plot, $subject, $joins);
            $search = $this->getSearch($plot, $subject);
            $countOnly = $this->isCountOnly($plot);
            if ($joins === []) {
                if (! $countOnly) {
                    $query = $this->applySearch($this->applyFilters($this->scopedQuery($model, $plot), $filters), $search)
                        ->limit($pagination['limit'])
                        ->offset($pagination['offset']);
                    foreach ($pagination['sorts'] as [$field, $direction]) {
                        $query->orderBy($field, $direction);
                    }
                    // The key breaks ties, so a page never repeats or skips a row.
                    $query->orderBy($keyName);
                    foreach ($query->get() as $record) {
                        $plot->data[] = $mapper->extractFromModel($record);
                    }
                }
                $pagination['total'] = $this->applySearch($this->applyFilters($this->scopedQuery($model, $plot), $filters), $search)->count();
            } else {
                $build = fn () => $this->applySearch($this->applyFilters($this->joinedQuery($model, $plot, $joins), $filters), $search);
                if (! $countOnly) {
                    $query = $build()->select('b.*')->limit($pagination['limit'])->offset($pagination['offset']);
                    foreach ($joins as $join) {
                        foreach ($join['columns'] as $name) {
                            $query->addSelect($join['alias'].'.'.$name.' as '.$join['alias'].'__'.$name);
                        }
                    }
                    foreach ($pagination['sorts'] as [$field, $direction]) {
                        $query->orderBy($this->qualify($field), $direction);
                    }
                    $query->orderBy('b.'.$keyName);
                    foreach ($query->get() as $row) {
                        $plot->data[] = $this->extractJoined((array) $row, $model, $mapper, $joins);
                    }
                }
                $pagination['total'] = $build()->count();
            }
            $pagination['pages'] = ceil($pagination['total'] / $pagination['limit']);
            $plot->setPagination($pagination);
        }

        return $plot;
    }

    /**
     * `count=only` returns the total of the filtered rows and no rows.
     *
     * @throws ValidationException
     */
    private function isCountOnly(StoryPlot $plot): bool
    {
        $count = $plot->requestData['data']['count'] ?? null;
        if ($count === null) {
            return false;
        }
        if ($count !== 'only') {
            throw new ValidationException(['count' => ['The count must be count=only']]);
        }

        return true;
    }

    /**
     * The filters of a list request, as `[field, operator, value]` triples.
     *
     * `filter[<field>]=<value>` is equality; `filter[<field>][<op>]=<value>` takes one of `eq`, `ne`,
     * `gt`, `gte`, `lt`, `lte`, `in` (comma separated values) or `contains`. Only mapped fields can be
     * filtered, never a password. Several filters combine with AND.
     *
     * @param  list<array<string, mixed>>  $joins
     * @return list<array{0: string, 1: string, 2: scalar|list<string>}>
     *
     * @throws ValidationException
     */
    private function getFilters(StoryPlot $plot, string $subject, array $joins = []): array
    {
        $filters = $plot->requestData['data']['filter'] ?? [];
        if (! is_array($filters)) {
            throw new ValidationException(['filter' => ['The filter must be filter[field]=value']]);
        }
        $types = $this->getMapper($subject)->getFields($subject)->pluck('type', 'name')->all();
        $allowed = array_keys(array_filter($types, fn ($type) => $type !== 'password'));
        foreach ($joins as $join) {
            foreach ($join['filterable'] as $name) {
                $allowed[] = $join['field'].'.'.$name;
            }
        }
        $errors = [];
        $parsed = [];
        foreach ($filters as $name => $condition) {
            if (! in_array($name, $allowed, true)) {
                $errors["filter.$name"] = ['Unknown filter field'];

                continue;
            }
            foreach (is_array($condition) ? $condition : ['eq' => $condition] as $operator => $value) {
                if (! in_array($operator, self::FILTER_OPERATORS, true)) {
                    $errors["filter.$name"] = ['Unknown filter operator'];
                } elseif (! is_scalar($value)) {
                    $errors["filter.$name"] = ['The filter value must be a single value'];
                } elseif ($operator === 'in') {
                    $parsed[] = [$name, $operator, explode(',', (string) $value)];
                } else {
                    $parsed[] = [$name, $operator, $value];
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $parsed;
    }

    /**
     * The `q` of a list request: one search term, at most 200 characters; an empty one is no search.
     * It matches (case-insensitive substring, literal) any string or text column of the table, never a
     * password, and combines with the filters by AND.
     *
     * @return array{columns: list<string>, term: string}|null
     *
     * @throws ValidationException
     */
    private function getSearch(StoryPlot $plot, string $subject): ?array
    {
        $term = $plot->requestData['data']['q'] ?? null;
        if ($term === null || $term === '') {
            return null;
        }
        if (! is_string($term)) {
            throw new ValidationException(['q' => ['The search must be q=text']]);
        }
        if (mb_strlen($term) > self::SEARCH_MAX_LENGTH) {
            throw new ValidationException(['q' => ['The search must be at most '.self::SEARCH_MAX_LENGTH.' characters']]);
        }
        $columns = $this->getMapper($subject)->getFields($subject)
            ->whereIn('type', self::SEARCH_TYPES)->pluck('name')->values()->all();

        return ['columns' => $columns, 'term' => $term];
    }

    /**
     * @param  array{columns: list<string>, term: string}|null  $search
     */
    private function applySearch(Builder|QueryBuilder $query, ?array $search): Builder|QueryBuilder
    {
        if ($search === null) {
            return $query;
        }
        $joined = $query instanceof QueryBuilder;
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search['term']).'%';

        // SQLite folds only ASCII case in LIKE: lower both sides with a multibyte-aware function.
        $connection = $query->getConnection();
        $fold = $connection instanceof Connection && $connection->getDriverName() === 'sqlite';
        if ($fold) {
            $like = mb_strtolower($like);
            $connection->getPdo()->sqliteCreateFunction(
                'pane_lower',
                static fn ($value) => $value === null ? null : mb_strtolower((string) $value),
                1,
                \PDO::SQLITE_DETERMINISTIC
            );
        }

        // No text column: nothing can match.
        return $query->where(function ($group) use ($search, $joined, $like, $fold): void {
            $group->whereRaw('1 = 0');
            foreach ($search['columns'] as $name) {
                $column = $group->getGrammar()->wrap($joined ? $this->qualify($name) : $name);
                $group->orWhereRaw(($fold ? 'pane_lower('.$column.')' : $column)." like ? escape '!'", [$like]);
            }
        });
    }

    /**
     * @param  list<array{0: string, 1: string, 2: scalar|list<string>}>  $filters
     */
    private function applyFilters(Builder|QueryBuilder $query, array $filters): Builder|QueryBuilder
    {
        $joined = $query instanceof QueryBuilder;
        foreach ($filters as [$name, $operator, $value]) {
            if ($joined) {
                $name = $this->qualify($name);
            }
            match ($operator) {
                'in' => $query->whereIn($name, $value),
                'contains' => $query->whereRaw(
                    $query->getGrammar()->wrap($name)." like ? escape '!'",
                    ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $value).'%']
                ),
                default => $query->where($name, self::SQL_OPERATORS[$operator], $value),
            };
        }

        return $query;
    }

    /**
     * `a.b` is the field `b` of the table joined through field `a` (alias `j_a`); a plain name is a
     * column of the base table (alias `b`).
     */
    private function qualify(string $name): string
    {
        return str_contains($name, '.') ? 'j_'.$name : 'b.'.$name;
    }

    /**
     * The `join=<field>[,<field>]` of a list request: one level only. A field can be joined when it
     * references another mapped table with an `exists:<table>,<primary key>` validation, so a base row
     * has at most one joined row. Each joined table is read through its own access rule.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     * @throws SystemException
     */
    private function getJoins(StoryPlot $plot, string $subject): array
    {
        $join = $plot->requestData['data']['join'] ?? null;
        if ($join === null) {
            return [];
        }
        $names = is_string($join) ? array_values(array_filter(explode(',', $join), fn ($n) => $n !== '')) : [];
        if ($names === [] || count($names) > self::JOIN_MAX || count(array_unique($names)) !== count($names)) {
            throw new ValidationException(['join' => ['The join must be join=field or join=field,field (at most '.self::JOIN_MAX.', one level)']]);
        }
        $base = app(AppMaps::class)->table(Str::snake($subject));
        $resolver = $plot->options['join_scope'] ?? null;
        $joins = [];
        foreach ($names as $field) {
            $reference = $base === null ? null : $this->reference($base, $field);
            if ($reference === null) {
                throw new ValidationException(['join' => ["Cannot join $field: it does not reference a mapped table"]]);
            }
            [$target, $column] = $reference;
            // The caller's rule on the joined table: protected tables are refused, as on the CRUD route itself.
            $scope = is_callable($resolver) ? $resolver($target->name) : null;
            $mapper = $this->getMapper($target->name);
            $fields = $mapper->getFields($target->name);
            $readable = $fields->where('type', '!=', 'password')->pluck('name')->all();
            $joins[] = [
                'field' => $field,
                'alias' => 'j_'.$field,
                'column' => $column,
                'scope' => $scope,
                'model' => $this->getModel($target->name),
                'mapper' => $mapper,
                'columns' => $fields->pluck('name')->all(),
                'filterable' => $readable,
                'sortable' => array_values(array_intersect($readable, $mapper->getIndexableFields())),
            ];
        }

        return $joins;
    }

    /**
     * @return array{0: MapTable, 1: string}|null the referenced table and its primary key column
     */
    private function reference(MapTable $base, string $field): ?array
    {
        $maps = app(AppMaps::class);
        $column = $base->fields()->where('name', $field)->first();
        if ($column === null || $column->type === 'password') {
            return null;
        }
        foreach ($column->validations ?? [] as $validation) {
            if (($validation['rule'] ?? null) !== 'exists' || ! is_string($validation['value'] ?? null)) {
                continue;
            }
            [$table, $key] = array_pad(explode(',', $validation['value'], 2), 2, null);
            $target = $maps->table($table);
            if ($target === null) {
                $bySql = MapTable::query()->where('sql_name', $table)->first();
                $target = $bySql === null ? null : $maps->table($bySql->name);
            }
            $primary = $target?->fields()->where('primary', true)->first();
            if ($target !== null && $primary !== null && ($key === null || $key === $primary->name)) {
                return [$target, $primary->name];
            }
        }

        return null;
    }

    /**
     * The base rows the caller may read, each left-joined to the one row it references, which only
     * joins when the caller may read that row too: a hidden row looks like a missing one.
     *
     * @param  list<array<string, mixed>>  $joins
     */
    private function joinedQuery(mixed $model, StoryPlot $plot, array $joins): QueryBuilder
    {
        $query = DB::query()->fromSub($this->scopedQuery($model, $plot)->toBase(), 'b');
        foreach ($joins as $join) {
            $sub = $join['model']->newQuery();
            if ($join['scope'] instanceof RowScope) {
                $join['scope']->restrict($sub);
            }
            $query->leftJoinSub($sub->toBase(), $join['alias'], 'b.'.$join['field'], '=', $join['alias'].'.'.$join['column']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $joins
     */
    private function extractJoined(array $row, mixed $model, mixed $mapper, array $joins): object
    {
        $base = [];
        $joined = [];
        foreach ($row as $name => $value) {
            if (str_starts_with($name, 'j_') && str_contains($name, '__')) {
                [$alias, $column] = explode('__', $name, 2);
                $joined[$alias][$column] = $value;
            } else {
                $base[$name] = $value;
            }
        }
        $result = $mapper->extractFromModel($model->newFromBuilder($base));
        $result->joined = new \stdClass;
        foreach ($joins as $join) {
            $attributes = $joined[$join['alias']] ?? [];
            $present = ($attributes[$join['column']] ?? null) !== null;
            $result->joined->{$join['field']} = $present
                ? $join['mapper']->extractFromModel($join['model']->newFromBuilder($attributes))
                : null;
        }

        return $result;
    }
}
