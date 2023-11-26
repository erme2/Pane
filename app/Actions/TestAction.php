<?php

namespace App\Actions;


use App\Stories\StoryPlot;

/**
 * Class TestAction
 * This action is here just for testing purposes
 *
 * @package App\Actions
 */

class TestAction extends AbstractAction
{
    /**
     * just a test action, it does nothing
     *
     * @param string $subject
     * @param $plot
     * @param $key
     * @return StoryPlot
     */
    public function exec(string $subject, $plot, $key = null): StoryPlot
    {
        // @todo: implement this action
        return $plot;
    }
}
