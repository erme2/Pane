<?php

namespace App\Models;

use App\Mappers\AbstractMapper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class FieldValidation
 * we will use this class to store the validation rules for the fields
 *
 * @package App\Models
 */

class FieldValidation extends Model
{
    use HasFactory;

    protected $table = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_validations'];

    protected $primaryKey = 'field_validation_id';

    /**
     * Setups the relationship with the field
     * @return ValidationType|null
     */
    public function getValidationType(): ?ValidationType
    {
        return $this->hasOne(ValidationType::class, 'validation_type_id', 'validation_type_id')->first();
    }
}
