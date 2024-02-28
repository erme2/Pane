<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use App\Models\AbstractModel;
use App\Stories\StoryPlot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Helper for App/Actions.
 *
 * @package App\Helpers
 */

trait ActionHelper
{
    use CoreHelper, ModelHelper;

    /**
     * Builds a mapper for the given subject.
     *
     * @param string $subject
     * @return AbstractMapper
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
                'Table for  () not found',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
        $class = new AbstractModel();
        $class->setMapName($subject);
        $class->setTable($class->getSqlTableName($subject));
        $class->setKeyName($class->getPrimaryKey($subject));
        return $class;
    }

    /**
     * Check if the story plot is a creation action.
     *
     * @param StoryPlot $plot
     * @return bool
     */
    public function isCreate(StoryPlot $plot): bool
    {
        if (isset($plot->options['is_new_record']) && $plot->options['is_new_record']) {
            return true;
        }
        return false;
    }
}
