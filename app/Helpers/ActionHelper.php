<?php

namespace App\Helpers;

use App\Mappers\AbstractMapper;
use App\Stories\StoryPlot;

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
