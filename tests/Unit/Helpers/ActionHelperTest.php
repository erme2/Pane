<?php

namespace Tests\Unit\Helpers;

use App\Mappers\AbstractMapper;

class ActionHelperTest extends \Tests\TestCase
{
    use \App\Helpers\ActionHelper;

    /**
     * @covers \App\Helpers\ActionHelper::getMapper
     *
     * @return void
     */
    public function test_get_mapper()
    {
        // @todo update test to use test mapper
        $mapper = $this->getMapper('Test');
        $this->assertInstanceOf(AbstractMapper::class, $mapper);
    }

    /**
     * @covers \App\Helpers\ActionHelper::isCreate
     *
     * @return void
     */
    public function test_is_create()
    {
        $plot = new \App\Stories\StoryPlot();
        $this->assertFalse($this->isCreate($plot));
        $plot->options['is_new_record'] = true;
        $this->assertTrue($this->isCreate($plot));
    }
}
