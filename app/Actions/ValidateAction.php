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
use App\Support\Maps\ReportScope;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
        // the reporter and the status of a new report are Pane's too (Pane#245)
        if ($scope instanceof ReportScope && $this->isCreate($plot)) {
            $plot->requestData['data'] = $scope->withStamp($plot->requestData['data']);
        }

        // if it's an update we need to add the primary key to the data array
        if (! $this->isCreate($plot)) {
            $plot->requestData['data'][$this->getModel($subject)->getKeyName()] = $key;
        }
        $rules = $mapper->getValidationRules($this->isCreate($plot));
        if (! $this->isCreate($plot)) {
            $rules = $this->ignoreCurrentRowInUniqueRules($rules, $key, $this->getModel($subject)->getKeyName());
        }
        $errors = Validator::make(
            $plot->requestData['data'],
            $rules,
            $mapper->getValidationMessages($this->isCreate($plot))
        )->errors();

        if ($errors->any()) {
            throw new ValidationException($errors->toArray());
        }

        $this->screenText($subject, $plot->requestData['data']);

        return $plot;
    }

    /**
     * A unique rule on update must not count the row being edited, or saving
     * it unchanged would be refused as already taken (Pane#240). Only the
     * literal `unique:table,column` rules that MapperHelper emits are changed.
     */
    private function ignoreCurrentRowInUniqueRules(array $rules, mixed $key, ?string $keyName): array
    {
        if ($key === null || $keyName === null) {
            return $rules;
        }

        foreach ($rules as $field => $fieldRules) {
            $parts = is_string($fieldRules) ? explode('|', $fieldRules) : (array) $fieldRules;
            foreach ($parts as $index => $part) {
                if (! is_string($part) || ! str_starts_with($part, 'unique:')) {
                    continue;
                }
                $arguments = explode(',', substr($part, strlen('unique:')));
                if (count($arguments) !== 2) {
                    continue;
                }
                $parts[$index] = Rule::unique($arguments[0], $arguments[1])->ignore($key, $keyName);
            }
            $rules[$field] = $parts;
        }

        return $rules;
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

        throw new ValidationException($errors, Response::HTTP_UNPROCESSABLE_ENTITY, 'text_rejected', (string) array_key_first($errors));
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
