<?php

namespace Tests\Feature\Helpers;

use App\Exceptions\SystemException;
use App\Helpers\ActionHelper;
use App\Mappers\AbstractMapper;
use App\Models\AbstractModel;
use App\Stories\StoryPlot;
use Tests\TestCase;

class ActionHelperTest extends TestCase
{
    use ActionHelper;

    /**
     * @covers \App\Helpers\ActionHelper::getMapper
     */
    public function test_get_mapper(): void
    {
        // @todo update test to use test mapper
        $mapper = $this->getMapper('Test');
        $this->assertInstanceOf(AbstractMapper::class, $mapper);
    }

    public function test_get_model()
    {
        // empty subject
        try {
            $this->getModel('');
        } catch (SystemException $e) {
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'Table for () not found', $e->getMessage());
            $this->assertEquals(500, $e->getCode());
        }
        // wrong subject
        try {
            $this->getModel('Test');
        } catch (SystemException $e) {
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'Table for Test (test) not found', $e->getMessage());
            $this->assertEquals(500, $e->getCode());
        }
        // ok
        $model = $this->getModel(AbstractMapper::TABLES['test_table']);
        $this->assertInstanceOf(AbstractModel::class, $model);
        $this->assertEquals('test_table', $model->getTable());
        $this->assertEquals(self::TEST_TABLE_PRIMARY_KEY, $model->getKeyName());
    }

    /**
     * @covers \App\Helpers\ActionHelper::isCreate
     */
    public function test_is_create(): void
    {
        $plot = new StoryPlot;
        $this->assertFalse($this->isCreate($plot));
        $plot->options['is_new_record'] = true;
        $this->assertTrue($this->isCreate($plot));
    }
}
