<?php

namespace Tests\Unit\Abstract;

use App\Mappers\AbstractMapper;
use Tests\TestCase;

class AbstractMapperTest extends TestCase
{

//    public function test_get_validation_rules(): void
//    {
//        $this->assertTrue(true);
//    }
//
//    public function test_get_validation_messages(): void
//    {
//        $this->assertTrue(true);
//    }

    public function test_get_table_id(): void
    {
        $testMapper = new class('table') extends AbstractMapper {};
        $this->assertEquals(1, $testMapper->getTableId());

//        $tableId =



    }
}
