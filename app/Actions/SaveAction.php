<?php

namespace App\Actions;

use App\Helpers\MapperHelper;
use App\Stories\StoryPlot;

class SaveAction extends AbstractAction
{
    use MapperHelper;

    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
print_R($plot);
die("AZAZA");

// check why I can't see the error

    }
}
