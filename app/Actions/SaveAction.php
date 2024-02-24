<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Helpers\ActionHelper;
use App\Helpers\MapperHelper;
use App\Stories\StoryPlot;
use Illuminate\Http\Response;

/**
 * Class SaveAction
 * This action will save every record in the plot->data array,
 * using the array keys to connect the data to the correct model.
 *
 * @package App\Actions
 */

class SaveAction extends AbstractAction
{
    use ActionHelper, MapperHelper;

    /**
     * This action will save every record in the plot->data array,
     * using the array keys to connect the data to the correct model.
     *
     * @param string $subject
     * @param StoryPlot $plot
     * @param mixed|null $key
     * @return StoryPlot
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        // no data no party
        if (empty($plot->requestData['data'])) {
            throw new SystemException('No data to save', Response::HTTP_NO_CONTENT);
        }
        $model = $this->getModel($subject);
        $mapper = $this->getMapper($subject);

        // is it create or update?
        if ($this->isCreate($plot)) {
            $model = $model->newInstance();
        } else {
            $primaryKey = $model->getKeyName();
            $key = $key ?? $plot->requestData['data'][$primaryKey];
            if (empty($key)) {
                throw new SystemException('No primary key to update', Response::HTTP_BAD_REQUEST);
            }
            $model = $model->find($key);
            // somehow after the find the primaryKey is reset to 'id'
            $model->setKeyName($primaryKey);
        }

        $model = $mapper->prepare($model, $plot->requestData['data']);
        try {
            $model->save();
            $plot->data = $model->toArray();
        } catch (\Exception $e) {
            throw new SystemException($e->getMessage());
        }

        return $plot;
    }
}
