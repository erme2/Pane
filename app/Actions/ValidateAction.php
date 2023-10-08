<?php

namespace App\Actions;

use App\Helpers\MapperHelper;
use App\Stories\StoryPlot;

class ValidateAction extends AbstractAction
{
    use MapperHelper;

    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
        $mapper = $this->getMapper($subject);
print_r($plot);
die("@ $subject");

    }
}
