<?php

namespace App\Stories;

use App\Helpers\StoryHelper;
use App\Helpers\StringHelper;
use Illuminate\Http\Request;

abstract class AbstractStory
{
    use StringHelper, StoryHelper;

    public array $actions = [];
    public StoryPlot $plot;

    public function __construct(Request $request)
    {
        $this->plot = new StoryPlot();
        $this->plot->setRequestData($request);
    }

    public function run(string $subject, $key = null): StoryPlot
    {
        foreach ($this->actions as $actionName) {
            $action = $this->loadAction($actionName);
            $this->plot = $action->exec($subject, $this->plot, $key);
        }

print_R($this->actions);
die('OK!');
    }
}
