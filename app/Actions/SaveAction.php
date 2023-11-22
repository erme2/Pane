<?php

namespace App\Actions;

use App\Helpers\MapperHelper;
use App\Stories\StoryPlot;

/**
 * Class SaveAction
 * This action will save every record in the plot->data array,
 * using the array keys to connect the data to the correct model.
 *
 * @package App\Actions
 */

class SaveAction extends AbstractAction
{
    use MapperHelper;

    /**
     * This action will save every record in the plot->data array,
     * using the array keys to connect the data to the correct model.
     *
     * @param string $subject
     * @param StoryPlot $plot
     * @param mixed|null $key
     * @return StoryPlot
     */
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot
    {
print_R($plot);
die("AZAZA");

// check why I can't see the error

    }
}
