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
        $validateData = $plot->requestData['data'];
print_R($validateData);
die("OKZA");

        // if it's an update we need to add the primary key to the data array
        if (!$this->isCreate($plot)) {
            $validateData[$this->getModel($subject)->getKeyName()] = $key;
        }

        $errors = Validator::make(
            $validateData,
            $mapper->getValidationRules(!$this->isCreate($plot), !$this->isCreate($plot)),
            $mapper->getValidationMessages(!$this->isCreate($plot))
        )->errors();

        if ($errors->any()) {
            throw new ValidationException($errors->toArray());
        }
        return $plot;
     }
}
