<?php

namespace App\Actions;

use App\Exceptions\SystemException;
use App\Stories\StoryPlot;

abstract class AbstractAction implements ActionInterface
{
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        throw new SystemException("Exec Method not implemented for ".get_class($this).".");
    }
}
