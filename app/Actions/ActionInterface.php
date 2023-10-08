<?php

namespace App\Actions;

use App\Stories\StoryPlot;

interface ActionInterface
{
    public function exec(string $subject, StoryPlot $plot, mixed $key = null): StoryPlot;
}
