<?php

namespace App\Stories;

use App\Helpers\StoryHelper;
use App\Helpers\StringHelper;

abstract class AbstractStory
{
    use StringHelper, StoryHelper;

    public array $actions = [];
    public StoryPlot $plot;

    public function run(): StoryPlot
    {

        $this->plot = new StoryPlot();
print_R($this->plot);
die('OK!');
    }
}
