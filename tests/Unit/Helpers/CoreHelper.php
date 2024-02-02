<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use Illuminate\Http\Response;
use Tests\TestCase;

class CoreHelper extends TestCase
{
    public function test_get_table_name(): void
    {
        // Unknown table
        $mapper = new class('InvalidName') extends AbstractMapper {};

        try {
            $res = $mapper->getTableName($mapper->name);
        } catch (\Exception $e) {
            $this->assertEquals(SystemException::class, get_class($e));
            $this->assertEquals('System Exception: Table for InvalidName (invalid_name) not found', $e->getMessage());
        }

        // test table
        $mapper = new class('TestTable') extends AbstractMapper {};
        $this->assertEquals(AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['test_table'], $mapper->getTableName($mapper->name));
    }
}
