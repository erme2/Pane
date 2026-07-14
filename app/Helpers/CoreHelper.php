<?php

namespace App\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CoreHelper
{
    /**
     * gets the table name from the tables map
     *
     * @throws SystemException
     */
    public function getSqlTableName(string $tableName): string
    {
        $name = (env('DB_TABLE_PREFIX')).AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['tables'];
        try {
            $table = DB::table($name)
                ->where('name', Str::snake($tableName))
                ->first();
        } catch (\Exception $e) {
            throw new SystemException(
                "Table for $tableName (".Str::snake($tableName).') not found',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        if ($table === null) {
            throw new SystemException(
                "Table for $tableName (".Str::snake($tableName).') not found',
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        return $table->{'sql_name'};
    }
}
