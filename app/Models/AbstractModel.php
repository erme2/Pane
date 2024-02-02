<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class AbstractModel extends Model
{

    public function __construct(string $tableName, string|array $primaryKey = 'id')
    {
        $tableName = Str::snake($tableName);

die("@ $tableName");

        parent::__construct();
        $this->table = $tableName;
    }
}
