<?php

namespace App\Helpers;

use App\Mappers\AbstractMapper;
use App\Stories\StoryPlot;

trait ActionHelper
{
    public function getMapper(string $subject): AbstractMapper
    {
        return new class($subject) extends AbstractMapper {};
    }

    public function isCreate(StoryPlot $plot): bool
    {
        if (isset($plot->options['is_new_record']) && $plot->options['is_new_record']) {
            return true;
        }
        return false;
    }
}
