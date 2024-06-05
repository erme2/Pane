<?php

namespace App\Actions;

use App\Exceptions\ValidationException;
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
    use ActionHelper;
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        $mapper = $this->getMapper($subject);
        $model = $this->getModel($subject);

        // if we have a key, we are looking for a specific record
        if ($key) {
            $keyName = $model->getPrimaryKey($subject);
            // and we want to validate the key
            $data = [$keyName => $key];
            $rules = $mapper->getValidationRules(true, true);
            $errors = Validator::make($data, $rules)->errors();
            if ($errors->any()) {
                throw new ValidationException($errors->toArray());
            } else {
                $plot->data[] = $mapper->extractFromModel($model->find($key));
            }
        } else {
            // todo write the pagination logic
            $plot->data = [];
        }

        return $plot;
    }
}
