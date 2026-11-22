<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Helpers\ActionHelper;
use App\Stories\StoryPlot;
use App\Support\Maps\MemberScope;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Class SaveAction
 * This action will save every record in the plot->data array,
 * using the array keys to connect the data to the correct model.
 */
class SaveAction extends AbstractAction
{
    use ActionHelper;

    /**
     * This action will save every record in the plot->data array,
     * using the array keys to connect the data to the correct model.
     *
     * @throws SystemException
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        // no data no party
        if (empty($plot->requestData['data'])) {
            throw new SystemException('No data to save', Response::HTTP_NO_CONTENT);
        }
        $model = $this->getModel($subject);
        $mapper = $this->getMapper($subject);

        $scope = $this->memberScope($plot);

        // is it create or update?
        if ($this->isCreate($plot)) {
            $scope?->assertCanCreate($plot->requestData['data']);
            $record = $model->newInstance();
        } else {
            $primaryKey = $model->getKeyName();
            $key = $key ?? $plot->requestData['data'][$primaryKey];
            if (empty($key)) {
                throw new SystemException('No primary key to update', Response::HTTP_BAD_REQUEST);
            }
            $record = $this->scopedQuery($model, $plot, true)->find($key);
            if ($record === null && $scope !== null) {
                // Same answer for a row that is missing and a row the user isn't a member of.
                throw $scope->forbidden();
            }
            $scope?->assertCanUpdate($plot->requestData['data'], $record?->{$scope->keyField});
        }
        if ($record) {
            $record = $mapper->fillModel($record, $plot->requestData['data']);
        } else {
            throw new SystemException('Record not found', Response::HTTP_NOT_FOUND);
        }

        try {
            DB::transaction(function () use ($record, $scope, $plot): void {
                $record->save();
                // A user who starts a new row in a parent table (a new list) becomes its first member.
                if ($scope instanceof MemberScope && $scope->keyIsPrimary && $this->isCreate($plot)) {
                    $scope->addCreator($record->getKey());
                }
            });
            $plot->data[] = $mapper->extractFromModel($record);
        } catch (\Exception $e) {
            throw new SystemException($e->getMessage());
        }

        return $plot;
    }
}
