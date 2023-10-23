<?php

namespace App\Models;

use App\Mappers\AbstractMapper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ValidationType extends Model
{
    use HasFactory;

    protected $table = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['validation_types'];

    protected $primaryKey = 'validation_type_id';
}
