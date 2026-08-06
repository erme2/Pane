<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\ActionHelper;
use App\Stories\StoryPlot;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class DeleteAction extends AbstractAction
{
    use ActionHelper;

    /**
     * @throws SystemException
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        $mapper = $this->getMapper($subject);
        $model = $this->getModel($subject);
        $keyName = $model->getPrimaryKey($subject);

        if (empty($key)) {
            throw new SystemException('Key is required', Response::HTTP_BAD_REQUEST);
        } else {
            try {
                $errors = Validator::make(
                    [$keyName => $key],
                    $mapper->getValidationRules(false, true)
                )->errors();
                if ($errors->any()) {
                    throw new ValidationException($errors->toArray());
                } else {
                    $record = $model->find($key);
                    if ($record) {
                        $record->delete();
                        $plot->setStatus(Response::HTTP_NO_CONTENT);
                    } else {
                        $plot->setStatus(Response::HTTP_NOT_FOUND);
                        $plot->data['message'] = "Record not found with {$model->getKeyName()}: $key";
                    }
                }
                // we need to catch ValidationException separately to rethrow it, so that it can be handled appropriately by the caller.
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Exception $e) {
                throw new SystemException($e->getMessage());
            }
        }

        return $plot;
    }
}
