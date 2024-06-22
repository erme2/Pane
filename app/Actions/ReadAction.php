<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\DefaultsHelper;
use App\Stories\StoryPlot;
use App\Helpers\ActionHelper;
use Illuminate\Support\Facades\Validator;

/**
 * Class ReadAction
 * This action will read the data from the database and return it in the response
 *
 * @package App\Actions
 */


class ReadAction extends AbstractAction
{
    use ActionHelper, DefaultsHelper;

    /**
     * @throws ValidationException
     * @throws SystemException
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        $mapper = $this->getMapper($subject);
        $model = $this->getModel($subject);
        $keyName = $model->getPrimaryKey($subject);

        // if we have a key, we are looking for a specific record
        if ($key) {
            // and we want to validate the key
            $errors = Validator::make(
                [$keyName => $key],
                $mapper->getValidationRules(true, true)
            )->errors();
            if ($errors->any()) {
                throw new ValidationException($errors->toArray());
            } else {
                try {
                    $plot->data[] = $mapper->extractFromModel($model->find($key));
                } catch (\Exception $e) {
                    throw new SystemException($e->getMessage());
                }
            }
        } else {
            $pagination = [
                'limit' => $plot->requestData['data']['limit'] ?? $this->default('PAGINATION_LIMIT'),
                'order' => $plot->requestData['data']['order'] ?? $this->default('PAGINATION_ORDER'),
                'sort' => $plot->requestData['data']['sort'] ?? $keyName,
                'page' => $plot->requestData['data']['page'] ?? 1,
            ];
            // validating the pagination data
            $paginationRules = [
                'limit' => 'integer|min:1|max:'.$this->default('PAGINATION_MAX'),
                'order' => 'in:asc,desc',
                'sort' => 'in:'.implode(',', $mapper->getIndexableFields()),
                'page' => 'integer|min:1',
            ];
            $errors = Validator::make($pagination, $paginationRules)->errors();
            if ($errors->any()) {
print_R($errors);
print_R($pagination);
die("@ $keyName");

                throw new ValidationException($errors->toArray());
            }
            $pagination['offset'] = ($pagination['page'] - 1) * $pagination['limit'];

            $query = $model
                ->limit($pagination['limit'])
                ->offset($pagination['offset'])
                ->orderBy($pagination['sort'], $pagination['order']);
            foreach ($query->get() as $record) {
                $plot->data[] = $mapper->extractFromModel($record);
            }
            $pagination['total'] = $model->count();
            $plot->setPagination($pagination);
        }

        return $plot;
    }
}
