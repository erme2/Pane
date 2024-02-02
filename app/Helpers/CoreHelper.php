<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CoreHelper
{
    /**
     * gets the table name from the tables map
     *
     * @param string $tableName
     * @return string
     * @throws SystemException
     */
    public function getTableName(string $tableName): string
    {
        try {
            return DB::table(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'])
                ->where('name', Str::snake($tableName))
                ->first()
                ->{'sql_name'};
        } catch (\Exception) {
            throw new SystemException("Table for $tableName (".Str::snake($tableName).") not found");
        }
    }
}
