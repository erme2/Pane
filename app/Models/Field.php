<?php

namespace App\Models;

use App\Mappers\AbstractMapper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Field extends Model
{
    use HasFactory;

    protected $table = AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['fields'];
    protected $primaryKey = 'field_id';

    public function fieldValidations(): HasMany
    {
        return $this->hasMany(FieldValidation::class, 'field_id', 'field_id');
    }

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
