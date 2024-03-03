<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\ActionHelper;
use App\Stories\StoryPlot;
use Illuminate\Support\Facades\Validator;

/**
 * Class ValidateAction
 * This action will validate the data in the request against the validation rules
 * defined in the mapper for the given subject.
 *
 * @package App\Actions
 */

class ValidateAction extends AbstractAction
{
    use ActionHelper;

    /**
     * Will validate the data in the request against the validation rules
     * defined in the mapper for the given subject.
     *
     * @param string $subject
     * @param StoryPlot $plot
     * @param mixed|null $key
     * @return StoryPlot
     * @throws ValidationException
     * @throws SystemException
     */
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
