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
            $query = $this->applyFilters($this->scopedQuery($model, $plot), $filters)
                ->limit($pagination['limit'])
                ->offset($pagination['offset'])
                ->orderBy($pagination['sort'], $pagination['order']);
            foreach ($query->get() as $record) {
                $plot->data[] = $mapper->extractFromModel($record);
            }
            $pagination['total'] = $this->applyFilters($this->scopedQuery($model, $plot), $filters)->count();
            $pagination['pages'] = ceil($pagination['total'] / $pagination['limit']);
            $plot->setPagination($pagination);
        }

        return $plot;
    }

    /**
     * The `filter[<field>]=<value>` query parameters: equality on mapped fields.
     * A filter on a field the map doesn't declare (or a password) is a 400.
     *
     * @return array<string, scalar>
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
        foreach ($filters as $name => $value) {
            if (! in_array($name, $allowed, true)) {
                $errors["filter.$name"] = ['Unknown filter field'];
            } elseif (! is_scalar($value)) {
                $errors["filter.$name"] = ['The filter value must be a single value'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $filters;
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as $name => $value) {
            $query->where($name, '=', $value);
        }

        return $query;
    }
}
