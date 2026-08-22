<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Stories\StoryPlot;

/**
 * Class AbstractAction
 * This is the base class for all actions
 */
abstract class AbstractAction implements ActionInterface
{
    /**
     * placeholder for the exec function that will be implemented in the child class
     *
     * @throws SystemException
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        throw new SystemException('Exec Method not implemented for '.get_class($this).'.');
    }
}
