<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\ActionHelper;
use App\Models\AuditEvent;
use App\Models\Field;
use App\Services\AuditEventService;
use App\Services\TextScreener;
use App\Stories\StoryPlot;
use App\Support\Maps\MemberScope;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
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

        // the creator column is Pane's to fill (Pane#227): it counts as sent by the signed-in user, whatever the client sent
        $scope = $this->memberScope($plot);
        if ($scope instanceof MemberScope) {
            $plot->requestData['data'] = $scope->withCreator($plot->requestData['data']);
        }

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

        $this->screenText($subject, $plot->requestData['data']);

        return $plot;
    }

    /**
     * Fields a map marks `screened` are checked after the access policy and
     * the other rules, before the row is saved (Pane#175). A refusal names the
     * field with a generic reason and is logged without the text.
     *
     * @throws ValidationException
     */
    private function screenText(string $subject, array $data): void
    {
        $screener = app(TextScreener::class);
        $errors = [];
        $rejected = [];
        /** @var Field $field */
        foreach ($this->getMapper($subject)->getFields($subject) as $field) {
            $name = (string) $field->getAttribute('name');
            $value = $data[$name] ?? null;
            if (! is_string($value) || $value === '' || ! $field->hasValidation('screened')) {
                continue;
            }
            $reason = $screener->screen($value);
            if ($reason !== null) {
                $errors[$name] = ['This text is not allowed.'];
                $rejected[$name] = $reason;
            }
        }
        if ($errors === []) {
            return;
        }

        app(AuditEventService::class)->record('data.text.screened', AuditEvent::OUTCOME_DENIED, [
            'effective_user' => Auth::user(),
            'table_name' => $subject,
            'changed_columns' => array_keys($rejected),
            'metadata' => ['reasons' => $rejected],
        ]);

        throw new ValidationException($errors, Response::HTTP_UNPROCESSABLE_ENTITY);
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
