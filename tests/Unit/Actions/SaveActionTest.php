<?php

namespace Tests\Unit\Actions;

use App\Actions\SaveAction;
use App\Exceptions\SystemException;
use App\Stories\StoryPlot;
use Illuminate\Http\Response;
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

    public function test_empty_data_exec(): void
    {
        try {
            $plot = $this->action->exec('TestTable', $this->mockStoryPlot);
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'No data to save', $e->getMessage());
            $this->assertEquals(Response::HTTP_NO_CONTENT, $e->getCode());
        }
    }

    public function test_create(): void
    {
        $this->mockStoryPlot->requestData['data'] = self::getValidTestTableRecord();
        $this->mockStoryPlot->options['is_new_record'] = true;
        $plot = $this->action->exec(self::TEST_TABLE_NAME, $this->mockStoryPlot);

        $this->assertInstanceOf(StoryPlot::class, $plot);
        $this->assertIsArray($plot->data);
        $this->assertIsObject($plot->data[0]);
//        foreach (self::getValidTestTableRecord() as $key => $value) {
//            $this->assertEquals($value, $plot->data[0]->$key);
//        }

print_R($plot->data[0]);
print_R($this->mockStoryPlot->requestData['data']);
die("ZAZA");
        print_R($plot);
        die("END TEST");

        $this->markTestIncomplete();
    }

    public function test_edit(): void
    {
        $this->mockStoryPlot->requestData['data'] = self::getUpdatedValidTestTableRecord();
        $this->mockStoryPlot->options['is_new_record'] = false;
        $plot = $this->action->exec(self::TEST_TABLE_NAME, $this->mockStoryPlot);
        print_R($plot);
        die("END TEST");

        $this->markTestIncomplete();
    }
}
