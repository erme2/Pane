<?php

namespace App\Actions;

use App\Stories\StoryPlot;

/**
 * Interface ActionInterface
 * This is the interface for all actions
 */
interface ActionInterface
{
    /**
     * Every action must implement an exec method.
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot;
}
