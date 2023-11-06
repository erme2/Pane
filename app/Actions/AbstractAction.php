<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use config\StoryPlot;

/**
 * Class AbstractAction
 * This is the base class for all actions
 *
 * @package App\Actions
 */

abstract class AbstractAction implements ActionInterface
{
    /**
     * placeholder for the exec function that will be implemented in the child class
     *
     * @param string $subject
     * @param StoryPlot $plot
     * @param mixed|null $key
     * @return StoryPlot
     * @throws SystemException
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        throw new SystemException("Exec Method not implemented for ".get_class($this).".");
    }
}
