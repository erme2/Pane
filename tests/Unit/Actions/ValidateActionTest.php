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
        $action = new ValidateAction();
        $this->assertInstanceOf('App\Actions\AbstractAction', $action);
        $this->assertInstanceOf('App\Actions\ValidateAction', $action);

        // using a `Create` story plot to test the validation
        $testStoryPlot = new StoryPlot();
        $testStoryPlot->options['is_new_record'] = true;

        $this->assertInstanceOf('App\Stories\StoryPlot', $testStoryPlot);
        try {
            $plot = $action->exec('TestTable', $testStoryPlot);
        } catch (\Exception $e) {
            $this->assertInstanceOf('App\Exceptions\ValidationException', $e);
            $errors = $e->getErrors();
print_R($errors);
die("sss");
        }


die("AZA");


        $this->assertInstanceOf('App\Stories\StoryPlot', $plot);

        $this->assertInstanceOf('App\Stories\StoryPlot', $plot);




        $this->markTestIncomplete();
    }
}
