<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use App\Models\AbstractModel;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\TestsHelper;

class ModelHelperTest extends TestCase
{
    use TestsHelper;

    public function test_get_primary_key(): void
    {
        // empty table name
        try {
            $model = new class('') extends AbstractModel {};
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'Table for  () not found', $e->getMessage());
            $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getCode());
        }

        try {
            $wrongName = 'not a table name';
            $model = new class($wrongName) extends AbstractModel {};
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'Table for '.Str::snake($wrongName).' ('.Str::snake($wrongName).') not found', $e->getMessage());
            $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getCode());
        }

        // main test
        $model = new class(self::TEST_TABLE_NAME) extends AbstractModel {};
        $this->assertEquals(self::TEST_TABLE_PRIMARY_KEY, $model->getPrimaryKey());

        // just a different table
        $model = new class(AbstractMapper::TABLES['field_validations']) extends AbstractModel {};
        $this->assertEquals('field_validation_id', $model->getPrimaryKey());
    }
}
