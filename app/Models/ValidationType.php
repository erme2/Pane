<?php

namespace App\Models;

use App\Mappers\AbstractMapper;
use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ValidationType
 * This class will be used to store the validation types for the fields
 */
class ValidationType extends Model
{
    use HasFactory;

    protected $table = AbstractMapper::TABLES['validation_types'];

    protected $primaryKey = 'validation_type_id';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = PaneTable::mapName($this->table);
    }
}
