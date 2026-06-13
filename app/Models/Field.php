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
 */
class Field extends Model
{
    use HasFactory;

    protected $table = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'];
    protected $fieldTypesTable = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['field_types'];
    protected $tablesTable = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'];
    protected $primaryKey = 'field_id';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = (env('DB_TABLE_PREFIX')) . $this->table;
        $this->fieldTypesTable = (env('DB_TABLE_PREFIX')) . $this->fieldTypesTable;
        $this->tablesTable = (env('DB_TABLE_PREFIX')) . $this->tablesTable;
    }


    /**
     * Returns a collection of field validations for the current field
     *
     * @param Field $field
     * @return Collection
     */
    public function getValidationFields(): Collection
    {
        return $this->hasMany(FieldValidation::class, 'field_id', 'field_id')
            ->where('field_id', $this->field_id)
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
                $this->table.".field_id",
                $this->table.".name",
                $this->table.".sql_name",
                $this->table.".primary",
                $this->table.".index",
                $this->table.".sortable",
                $this->table.".nullable",
                $this->table.".default",
                $this->fieldTypesTable.".name as type",
            ])
            ->join($this->tablesTable,
                $this->table.'.table_id', '=',
                $this->tablesTable.'.table_id'
            )
            ->join($this->fieldTypesTable,
                $this->table.'.field_type_id', '=',
                $this->fieldTypesTable.'.field_type_id'
            )
            ->where($this->tablesTable.'.name', $table);
        return $query->get();
    }

    /**
     * checks if a field has a specific validation
     *
     * @param array $what
     * @return bool
     */
    public function hasValidation(string $what): bool
    {
        $validationRules = $this->getValidationFields();
        foreach ($validationRules as $validationRule) {
            if ($validationRule->validation_type_id === AbstractMapper::VALIDATION_TYPES[$what]) {
                return true;
            }
        }
        return false;
    }
}
