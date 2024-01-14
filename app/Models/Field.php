<?php

namespace App\Models;

use App\Mappers\AbstractMapper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Field
 * we will use this class to get fields for every table described in the database
 *
 * @package App\Models
 * @property int $field_id
 * @property string $name
 * @property string $sql_name
 * @property int $field_type_id
 * @property int $table_id
 * @property bool $primary
 * @property bool $index
 * @property bool $nullable
 * @property string $default
 */
class Field extends Model
{
    use HasFactory;

    protected $table = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'];
    protected $primaryKey = 'field_id';

    /**
     * Returns a collection of field validations for the current field
     *
     * @param Field $field
     * @return Collection
     */
    public function getValidationFields(Field $field): Collection
    {
        return $this->hasMany(FieldValidation::class, 'field_id', 'field_id')
            ->where('field_id', $field->field_id)
            ->get();
    }

    /**
     * this method will return a collection of fields for the given table with all the relevant information about the
     * table they are linked to, the field type and the validations that are applied to them
     *
     * @param string $table
     * @return Collection
     */
    public function getFields(string $table): Collection
    {
        $query = $this
            ->select([
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'].".field_id",
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'].".name",
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'].".sql_name",
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'].".primary",
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'].".index",
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'].".nullable",
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'].".default",
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_types'].".name as type",
            ])
            ->join(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'],
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'].'.table_id', '=',
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'].'.table_id'
            )
            ->join(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_types'],
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'].'.field_type_id', '=',
                AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_types'].'.field_type_id'
            )
            ->where(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'].'.name', $table)
        ;
        return $query->get();
    }
}
