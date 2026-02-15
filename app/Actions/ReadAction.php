<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\DefaultsHelper;
use App\Helpers\PaginationHelper;
use App\Mappers\AbstractMapper;
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
    use ActionHelper, PaginationHelper;

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
                $mapper->getValidationRules(false, true)
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
            $pagination = $this->getPaginationData($plot, $subject);
            $query = $model
                ->limit($pagination['limit'])
                ->offset($pagination['offset'])
                ->orderBy($pagination['sort'], $pagination['order']);
            foreach ($query->get() as $record) {
                $plot->data[] = $mapper->extractFromModel($record);
            }
            $pagination['total'] = $model->count();
            $pagination['pages'] = ceil($pagination['total'] / $pagination['limit']);
            $plot->setPagination($pagination);
        }

        return $plot;
    }
}
