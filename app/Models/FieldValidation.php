<?php

namespace App\Models;

use App\Mappers\AbstractMapper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FieldValidation extends Model
{
    use HasFactory;

    protected $table = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'];

    protected $primaryKey = 'field_validation_id';

    public function getValidationType(): ?ValidationType
    {
        return $this->hasOne(ValidationType::class, 'validation_type_id', 'validation_type_id')->first();
    }
}
