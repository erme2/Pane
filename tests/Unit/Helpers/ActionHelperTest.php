<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Helpers\ActionHelper;
use App\Mappers\AbstractMapper;
use Tests\TestCase;

class ActionHelperTest extends TestCase
{
    use ActionHelper;

    /**
     * @covers \App\Helpers\ActionHelper::getMapper
     *
     * @return void
     */
    public function test_get_mapper(): void
    {
        // @todo update test to use test mapper
        $mapper = $this->getMapper('Test');
        $this->assertInstanceOf(AbstractMapper::class, $mapper);
    }

    public function test_get_model()
    {
        $this->markTestIncomplete();
    }

    /**
     * @covers \App\Helpers\ActionHelper::isCreate
     *
     * @return void
     */
    public function test_is_create(): void
    {
        $plot = new \App\Stories\StoryPlot();
        $this->assertFalse($this->isCreate($plot));
        $plot->options['is_new_record'] = true;
        $this->assertTrue($this->isCreate($plot));
    }

}
