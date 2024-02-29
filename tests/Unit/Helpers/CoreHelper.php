<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use Tests\TestCase;

class CoreHelper extends TestCase
{
    public function test_get_sql_table_name(): void
    {
        // Unknown table
        $mapper = new class('InvalidName') extends AbstractMapper {};

        try {
            $mapper->getSqlTableName($mapper->name);
        } catch (\Exception $e) {
            $this->assertEquals(SystemException::class, get_class($e));
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'Table for InvalidName (invalid_name) not found', $e->getMessage());
        }

        // test table
        $mapper = new class('TestTable') extends AbstractMapper {};
        $this->assertEquals(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['test_table'], $mapper->getSqlTableName($mapper->name));
    }
}
