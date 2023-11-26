<?php

namespace App\Helpers;

use App\Mappers\AbstractMapper;
use Illuminate\Database\Eloquent\Model;

/**
 * Helper for App/Mappers.
 *
 * @package App\Helpers
 */

trait MapperHelper
{

    /**
     * Builds and return a map of the given subject.
     *
     * @param string $subject
     * @param array $data
     * @return Model
     */
    public function map(string $subject, array $data): Model
    {
print_R($data);
die("@ $subject");

    }
}
