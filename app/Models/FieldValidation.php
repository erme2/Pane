<?php

namespace App\Models;

use App\Mappers\AbstractMapper;
use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class FieldValidation
 * we will use this class to store the validation rules for the fields
 */
class FieldValidation extends Model
{
    use HasFactory;

    protected $table = AbstractMapper::TABLES['field_validations'];

    protected $primaryKey = 'field_validation_id';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = PaneTable::mapName($this->table);
    }

    /**
     * Setups the relationship with the field
     */
    public function getValidationType(): ?ValidationType
    {
        return $this->hasOne(ValidationType::class, 'validation_type_id', 'validation_type_id')->first();
    }
}
