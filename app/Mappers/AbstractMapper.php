<?php

namespace App\Mappers;

use App\Exceptions\SystemException;
use App\Helpers\MapperHelper;
use App\Helpers\StringHelper;
use App\Models\Field;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Class AbstractMapper
 * this class will be extended by all mappers
 *
 * @package App\Mappers
 */

abstract class AbstractMapper
{
    use MapperHelper, StringHelper;

    const MAP_TABLES_PREFIX = 'map_';
    const TABLES = [
        'tables' => 'tables',
        'fields' => 'fields',
        'field_types' => 'field_types',
        'field_validations' => 'field_validations',
        'validation_types' => 'validation_types',
        'users' => 'users',
        'user_types' => 'user_types',
        'test_table' => 'test_table',
    ];
    const FIELD_TYPES = [
        'number' => 1,
        'string' => 2,
        'text' => 3,
        'boolean' => 4,
        'date' => 5,
        'array' => 6,
        'password' => 7,
        'json' => 8,
    ];
    const VALIDATION_TYPES = [
        'unique' => 1,
        'exists' => 2,
        'max' => 3,
        'min' => 4,
        'email' => 5,
    ];
    private string $name;
    public function __construct(string $name)
    {
        $this->name = $name;
    }




}
