<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Helpers\ActionHelper;
use App\Mappers\AbstractMapper;
use App\Models\AbstractModel;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\TestsHelper;

class ModelHelperTest extends TestCase
{
    use ActionHelper, TestsHelper;

    public function test_get_primary_key(): void
    {
        // empty table name
        try {
            $model = $this->getModel('');
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'Table for  () not found', $e->getMessage());
            $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getCode());
        }

        try {
            $wrongName = 'not a table name';
            $model = $this->getModel($wrongName);
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'Table for '.Str::snake($wrongName).' ('.Str::snake($wrongName).') not found', $e->getMessage());
            $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getCode());
        }

        // main test
        $model = $this->getModel(self::TEST_TABLE_NAME);
        $this->assertEquals(self::TEST_TABLE_PRIMARY_KEY, $model->getPrimaryKey());

        // just a different table
        $model = $this->getModel(AbstractMapper::TABLES['field_validations']);
        $this->assertEquals('field_validation_id', $model->getPrimaryKey());
    }
}
