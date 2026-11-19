<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use App\Models\AbstractModel;
use App\Stories\StoryPlot;
use App\Support\Maps\MemberScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

/**
 * Helper for App/Actions.
 */
trait ActionHelper
{
    use CoreHelper, ModelHelper;

    /**
     * Builds a mapper for the given subject.
     */
    public function getMapper(string $subject): AbstractMapper
    {
        return new class($subject) extends AbstractMapper {};
    }

    /**
     * @throws SystemException
     */
    public function getModel(string $subject): AbstractModel
    {
        if (empty($subject)) {
            throw new SystemException(
                'Table for () not found',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
        $class = new AbstractModel;
        $class->setMapName($subject);
        $class->setTable($class->getSqlTableName($subject));
        $class->setKeyName($class->getPrimaryKey($subject));

        return $class;
    }

    /**
     * The members rule the controller set for this request, if any (Pane#185).
     */
    public function memberScope(StoryPlot $plot): ?MemberScope
    {
        $scope = $plot->options['member_scope'] ?? null;

        return $scope instanceof MemberScope ? $scope : null;
    }

    /**
     * A query on the model that only reaches the rows the user may access.
     *
     * @return Builder<AbstractModel>
     */
    public function scopedQuery(AbstractModel $model, StoryPlot $plot): Builder
    {
        $query = $model->newQuery();
        $scope = $this->memberScope($plot);

        return $scope === null ? $query : $scope->restrict($query);
    }

    /**
     * Check if the story plot is a creation action.
     */
    public function isCreate(StoryPlot $plot): bool
    {
        if (isset($plot->options['is_new_record']) && $plot->options['is_new_record']) {
            return true;
        }

        return false;
    }
}
