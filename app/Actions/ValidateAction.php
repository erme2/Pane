<?php

namespace App\Actions;

use App\Exceptions\ValidationException;
use App\Helpers\ActionHelper;
use App\Stories\StoryPlot;
use Illuminate\Support\Facades\Validator;

class ValidateAction extends AbstractAction
{
    use ActionHelper;

    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        $mapper = $this->getMapper($subject);

        $errors = Validator::make(
            $plot->requestData['data'],
            $mapper->getValidationRules(!$this->isCreate($plot)),
            $mapper->getValidationMessages(!$this->isCreate($plot))
        )->errors();

        if ($errors->any()) {
            throw new ValidationException($errors->toArray());
        }
        $plot->data[$subject][] = $plot->requestData['data'];

        return $plot;
     }
}
