<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\PaginationHelper;
use App\Stories\StoryPlot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Class ReadAction
 * This action will read the data from the database and return it in the response
 */
class ReadAction extends AbstractAction
{
    use PaginationHelper;

    private const FILTER_OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'contains'];

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
            $pagination = $this->getPaginationData($plot, $subject);
            $filters = $this->getFilters($plot, $subject);
            $countOnly = $this->isCountOnly($plot);
            if (! $countOnly) {
                $query = $this->applyFilters($this->scopedQuery($model, $plot), $filters)
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
            $pagination['total'] = $this->applyFilters($this->scopedQuery($model, $plot), $filters)->count();
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
     * @return list<array{0: string, 1: string, 2: scalar|list<string>}>
     *
     * @throws ValidationException
     */
    private function getFilters(StoryPlot $plot, string $subject): array
    {
        $filters = $plot->requestData['data']['filter'] ?? [];
        if (! is_array($filters)) {
            throw new ValidationException(['filter' => ['The filter must be filter[field]=value']]);
        }
        $types = $this->getMapper($subject)->getFields($subject)->pluck('type', 'name')->all();
        $allowed = array_keys(array_filter($types, fn ($type) => $type !== 'password'));
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
     * @param  list<array{0: string, 1: string, 2: scalar|list<string>}>  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as [$name, $operator, $value]) {
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
}
