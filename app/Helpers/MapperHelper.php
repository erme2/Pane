<?php

namespace App\Helpers;

use App\Mappers\AbstractMapper;

trait MapperHelper
{
    public function getMapper(string $subject): AbstractMapper
    {
        return new class($subject) extends AbstractMapper {};
    }
}
