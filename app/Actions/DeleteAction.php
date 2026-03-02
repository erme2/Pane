<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Helpers\ActionHelper;
use App\Stories\StoryPlot;
use Illuminate\Http\Response;

class DeleteAction extends AbstractAction
{
    use ActionHelper;

    /**
     * @param string $subject
     * @param StoryPlot $plot
     * @param mixed|null $key
     * @return StoryPlot
     * @throws SystemException
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        $model = $this->getModel($subject);

        try {
            $record = $model->find($key);
            if ($record) {
                $record->delete();
                $plot->setStatus(Response::HTTP_NO_CONTENT);
            } else {
                $plot->setStatus(Response::HTTP_NOT_FOUND);
                $plot->data['message'] = "Record not found with {$model->getKeyName()}: $key";
            }
        } catch (\Exception $e) {
            throw new SystemException($e->getMessage());
        }

        return $plot;
    }
}
