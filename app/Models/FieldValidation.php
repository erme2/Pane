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
 * @property int $field_validation_id
 * @property int $field_id
 * @property int $validation_type_id
 * @property string $validation_value
 * @property string $created_at
 * @property string $updated_at
 */

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
