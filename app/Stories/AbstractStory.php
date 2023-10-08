<?php

namespace App\Stories;

use App\Helpers\StoryHelper;
use App\Helpers\StringHelper;

abstract class AbstractStory
{
    use StringHelper, StoryHelper;

    public array $actions = [];
    public StoryPlot $plot;

    public function run(string $subject, $key = null): StoryPlot
    {
        $this->plot = new StoryPlot();

        foreach ($this->actions as $actionName) {
            $action = $this->loadAction($actionName);
            $this->plot = $action->exec($subject, $this->plot, $key);
        }

print_R($this->actions);
die('OK!');
    }
}
