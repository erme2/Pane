<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\PaginationHelper;
use App\Stories\StoryPlot;
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
            $query = $this->scopedQuery($model, $plot)
                ->limit($pagination['limit'])
                ->offset($pagination['offset'])
                ->orderBy($pagination['sort'], $pagination['order']);
            foreach ($query->get() as $record) {
                $plot->data[] = $mapper->extractFromModel($record);
            }
            $pagination['total'] = $this->scopedQuery($model, $plot)->count();
            $pagination['pages'] = ceil($pagination['total'] / $pagination['limit']);
            $plot->setPagination($pagination);
        }

        return $plot;
    }
}
