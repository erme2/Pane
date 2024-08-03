<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Stories\StoryPlot;
use Illuminate\Support\Facades\Validator;

trait PaginationHelper
{
    use DefaultsHelper;

    /**
     * Creates and return a pagination data array from a StoryPlot object and a Subject
     *
     * @param StoryPlot $plot
     * @param string $subject
     * @return array
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
            'limit' => 'integer|min:1|max:' . $this->default('PAGINATION_MAX'),
            'order' => 'string|in:asc,desc',
            'sort' => 'string|in:'.implode(',', $mapper->getIndexableFields()),
            'page' => 'integer|min:1',
        ];
        $errors = Validator::make($data, $rules)->errors();
        if ($errors->any()) {
            throw new ValidationException($errors->toArray());
        }
        $data['offset'] = ($data['page'] - 1) * $data['limit'];
        return $data;
    }
}
