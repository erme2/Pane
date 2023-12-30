<?php

namespace Tests\Unit\Actions;

use App\Actions\ValidateAction;
use App\Exceptions\ValidationException;
use App\Stories\StoryPlot;
use Tests\TestCase;

class ValidateActionTest extends TestCase
{
    private StoryPlot $mockStoryPlot;

    /**
     * @covers \App\Actions\AbstractAction::__construct
     *
     * @param string $name
     */
    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->mockStoryPlot = new StoryPlot();
    }

    /**
     * @covers \App\Actions\AbstractAction::exec
     *
     * @return void
     * @throws ValidationException
     */
    public function test_exec(): void
    {
        // @todo replace user with some test table/data
        $action = new ValidateAction();
        $this->assertInstanceOf('App\Actions\AbstractAction', $action);
        $this->assertInstanceOf('App\Actions\ValidateAction', $action);

        // create
        $testStoryPlot = new StoryPlot();
        $testStoryPlot->options['is_new_record'] = true;

        $this->assertInstanceOf('App\Stories\StoryPlot', $testStoryPlot);
        $plot = $action->exec('test', $testStoryPlot);
        $this->assertInstanceOf('App\Stories\StoryPlot', $plot);
print_R($plot);
die("AZA");

        $this->assertInstanceOf('App\Stories\StoryPlot', $plot);




        $this->markTestIncomplete();
    }
}
