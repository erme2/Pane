<?php

namespace App\Actions;

use App\Exceptions\PaneException;
use App\Stories\StoryPlot;

abstract class AbstractAction implements ActionInterface
{
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        throw new PaneException("Exec Method not implemented for ".get_class($this).".");
    }
}
