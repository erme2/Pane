<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Mappers\AbstractMapper;
use App\Stories\StoryPlot;
use Illuminate\Support\Facades\Validator;

trait PaginationHelper
{
    use ActionHelper, DefaultsHelper;

    private const SORT_MAX_FIELDS = 2;

    /**
     * Creates and return an array built from the plot data you can use to paginate the data in the database.
     * It will also validate the pagination data and throw a ValidationException if the data is invalid.
     *
     * @throws ValidationException
     * @throws SystemException
     */
    public function getPaginationData(StoryPlot $plot, string $subject): array
    {
        $mapper = $this->getMapper($subject);
        $keyName = ($this->getModel($subject))->getPrimaryKey($subject);

        $data = [
            'limit' => isset($plot->requestData['data']['limit']) ?
                (int) $plot->requestData['data']['limit'] : $this->default('PAGINATION_LIMIT'),
            'order' => $plot->requestData['data']['order'] ?? $this->default('PAGINATION_ORDER'),
            'sort' => $plot->requestData['data']['sort'] ?? $keyName,
            'page' => isset($plot->requestData['data']['page']) ? (int) $plot->requestData['data']['page'] : 1,
        ];
        $rules = [
            'limit' => 'integer|min:1|max:'.$this->default('PAGINATION_MAX'),
            'order' => 'string|in:asc,desc',
            'sort' => 'string',
            'page' => 'integer|min:1',
        ];
        $errors = Validator::make($data, $rules)->errors()->toArray();
        if ($errors === []) {
            [$data['sorts'], $errors] = $this->parseSorts($data['sort'], $data['order'], $this->sortableFields($mapper));
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $data['offset'] = ($data['page'] - 1) * $data['limit'];

        return $data;
    }

    /**
     * `sort=a,b` (at most 2 fields, each one of the map's sortable fields). A `-` prefix sorts that
     * field descending; the others follow `order`.
     *
     * @param  list<string>  $sortable
     * @return array{0: list<array{0: string, 1: string}>, 1: array<string, list<string>>}
     */
    private function parseSorts(string $sort, string $order, array $sortable): array
    {
        $names = array_filter(explode(',', $sort), fn (string $name) => $name !== '');
        if (count($names) > self::SORT_MAX_FIELDS) {
            return [[], ['sort' => ['Sort on at most '.self::SORT_MAX_FIELDS.' fields']]];
        }
        $sorts = [];
        foreach ($names as $name) {
            $direction = str_starts_with($name, '-') ? 'desc' : $order;
            $field = ltrim($name, '-');
            if (! in_array($field, $sortable, true)) {
                return [[], ['sort' => ['The selected sort field is invalid']]];
            }
            $sorts[] = [$field, $direction];
        }

        return [$sorts, []];
    }

    /**
     * The map's indexed or sortable fields, without passwords: a field the caller cannot read is not a sort key.
     *
     * @return list<string>
     */
    private function sortableFields(AbstractMapper $mapper): array
    {
        $passwords = $mapper->getFields($mapper->name)->where('type', 'password')->pluck('name')->all();

        return array_values(array_diff($mapper->getIndexableFields(), $passwords));
    }
}
