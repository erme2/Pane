<?php

namespace App\Helpers;

use App\Mappers\AbstractMapper;
use Illuminate\Database\Eloquent\Model;

trait MapperHelper
{

    public function map(string $subject, array $data): Model
    {
print_R($data);
die("@ $subject");

    }
}
