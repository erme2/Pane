<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\ActionHelper;
use App\Stories\StoryPlot;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Class ValidateAction
 * This action will validate the data in the request against the validation rules
 * defined in the mapper for the given subject.
 */
class ValidateAction extends AbstractAction
{
    use ActionHelper;

    /**
     * Will validate the data in the request against the validation rules
     * defined in the mapper for the given subject.
     *
     * @throws ValidationException
     * @throws SystemException
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        $mapper = $this->getMapper($subject);

        // what the client sent, before the action adds the primary key itself
        $this->rejectUnknownFields($subject, $plot->requestData['data']);

        // if it's an update we need to add the primary key to the data array
        if (! $this->isCreate($plot)) {
            $plot->requestData['data'][$this->getModel($subject)->getKeyName()] = $key;
        }
        $errors = Validator::make(
            $plot->requestData['data'],
            $mapper->getValidationRules($this->isCreate($plot)),
            $mapper->getValidationMessages($this->isCreate($plot))
        )->errors();

        if ($errors->any()) {
            throw new ValidationException($errors->toArray());
        }

        return $plot;
    }

    /**
     * A write that names a column the map doesn't declare is refused with 422
     * and nothing is written. Keys starting with an underscore (for example
     * `_token`) belong to the framework and are ignored.
     *
     * @throws ValidationException
     */
    private function rejectUnknownFields(string $subject, array $data): void
    {
        $known = $this->getMapper($subject)->getFields($subject)->pluck('name')->all();
        if ($known === []) {
            // not a mapped table: the rest of the action reports that
            return;
        }
        $errors = [];
        foreach (array_keys($data) as $name) {
            if (! in_array($name, $known, true) && ! str_starts_with((string) $name, '_')) {
                $errors[$name] = ['Unknown field'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
