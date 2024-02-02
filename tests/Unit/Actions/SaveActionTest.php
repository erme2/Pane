<?php

namespace Tests\Unit\Actions;

use App\Actions\SaveAction;
use App\Stories\StoryPlot;
use Tests\TestCase;
use Tests\TestsHelper;

class SaveActionTest extends TestCase
{
    use TestsHelper;
    private StoryPlot $mockStoryPlot;
    private SaveAction $action;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->mockStoryPlot = new StoryPlot();
        $this->action = new SaveAction();
    }

    public function test_exec(): void
    {
//        $plot = $this->action->exec('TestTable', $this->mockStoryPlot);
//print_R($plot);
//die("END TEST");

        $this->markTestIncomplete();
    }
}
