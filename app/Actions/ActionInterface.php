<?php

namespace App\Actions;

use config\StoryPlot;

/**
 * Interface ActionInterface
 * This is the interface for all actions
 *
 * @package App\Actions
 */

interface ActionInterface
{
    /**
     * Every action must implement an exec method.
     *
     * @param string $subject
     * @param StoryPlot $plot
     * @param mixed|null $key
     * @return StoryPlot
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot;
}
