<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use App\Models\AbstractModel;
use App\Stories\StoryPlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Helper for App/Actions.
 *
 * @package App\Helpers
 */

trait ActionHelper
{
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
     * gets the table name from the tables map
     *
     * @return string
     * @throws SystemException
     */
    public function getTableName(string $tableName): string
    {
        try {
            return DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])
                ->where('name', Str::snake($tableName))
                ->first()
                ->{'sql_name'}
                ;
        } catch (\Exception) {
            throw new SystemException("Table for $this->name (".Str::snake($this->name).") not found");
        }
    }

    public function getModel(string $subject): AbstractModel
    {
        return new class($subject) extends AbstractModel {};
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
