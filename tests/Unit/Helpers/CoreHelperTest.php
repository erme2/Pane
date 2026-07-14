<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use Illuminate\Http\Response;
use Tests\TestCase;

class CoreHelperTest extends TestCase
{
    public function test_get_sql_table_name_throws_system_exception_for_unknown_subject(): void
    {
        $mapper = new class('InvalidName') extends AbstractMapper {};

        $this->expectException(SystemException::class);
        $this->expectExceptionCode(Response::HTTP_INTERNAL_SERVER_ERROR);
        $this->expectExceptionMessage(
            SystemException::ERROR_MESSAGE_PREFIX.'Table for InvalidName (invalid_name) not found'
        );

        $mapper->getSqlTableName($mapper->name);
    }

    public function test_get_sql_table_name_returns_mapped_sql_table_name(): void
    {
        $mapper = new class('TestTable') extends AbstractMapper {};

        $this->assertEquals(AbstractMapper::TABLES['test_table'], $mapper->getSqlTableName($mapper->name));
    }
}
