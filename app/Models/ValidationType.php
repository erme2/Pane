<?php

namespace App\Models;

use App\Mappers\AbstractMapper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ValidationType
 * This class will be used to store the validation types for the fields
 *
 * @package App\Models
 * @property int $validation_type_id
 * @property string $validation_type_name
 */

class ValidationType extends Model
{
    use HasFactory;

    protected $table = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['validation_types'];

    protected $primaryKey = 'validation_type_id';
}
