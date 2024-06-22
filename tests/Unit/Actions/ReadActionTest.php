<?php

namespace Tests\Unit\Actions;

use App\Actions\ReadAction;
use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Stories\StoryPlot;
use Illuminate\Http\Response;
use Tests\TestCase;
use Tests\TestsHelper;

class ReadActionTest extends TestCase
{
    use TestsHelper;

    private StoryPlot $mockStoryPlot;
    private ReadAction $action;

    public function setUp(): void
    {
        parent::setUp();
        $this->mockStoryPlot = new StoryPlot();
        $this->mockStoryPlot->requestData['method'] = 'GET';
        $this->action = new ReadAction();
    }

    public function testExecWithEmptyKey()
    {
        $plot = $this->action->exec('test_table', $this->mockStoryPlot);
        // it should return the first page
        $this->assertInstanceOf(StoryPlot::class, $plot);
        $this->assertIsArray($plot->data);
        $this->assertIsArray($plot->getPagination());
        $this->assertEquals(25, count($plot->data));
        $this->assertEquals(25, $plot->getPagination()['limit']);
        $this->assertEquals('asc', $plot->getPagination()['order']);
        $this->assertEquals('table_id', $plot->getPagination()['sort']);
        $this->assertEquals(1, $plot->getPagination()['page']);
        $this->assertEquals(0, $plot->getPagination()['offset']);
        $this->assertEquals(1001, $plot->getPagination()['total']);
    }

    public function testExecWithInvalidKey()
    {
        try {
            $this->action->exec('test_table', $this->mockStoryPlot, 'A');
        } catch (\Exception $e) {
            $this->assertInstanceOf(ValidationException::class, $e);
            $this->assertEquals('Validation failed', $e->getMessage());
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $e->getCode());
        }
    }

    public function testExecWithWrongKey()
    {
        try {
            $this->action->exec('test_table', $this->mockStoryPlot, 999999);
        } catch (\Exception $e) {
            $this->assertInstanceOf(ValidationException::class, $e);
            $this->assertEquals('Validation failed', $e->getMessage());
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $e->getCode());
        }
    }

    public function testExecWithKey()
    {
        $key = 1;
        $plot = $this->action->exec('test_table', $this->mockStoryPlot, $key);
        $this->assertInstanceOf(StoryPlot::class, $plot);
        $this->assertIsArray($plot->data);
        $this->assertIsArray($plot->getPagination());
        $this->assertEquals(1, count($plot->data));
        $this->assertEquals(0, count($plot->getPagination()));
        $this->assertEquals($key, $plot->data[0]->table_id);

        $log = $plot->getLogs();
        $this->assertIsArray($log);
        $this->assertEquals(3, count($log));
        foreach ($log as $messageType) {
            $this->assertIsArray($messageType);
            $this->assertEquals(0, count($messageType));
        }
    }

    public function testPagination()
    {
        $this->mockStoryPlot->requestData['data'] = [
            'limit' => 25,
            'order' => 'desc',
            'sort' => 'table_id',
            'page' => 2,
        ];
        $plot = $this->action->exec('test_table', $this->mockStoryPlot);
        // it should return the first page
        $this->assertInstanceOf(StoryPlot::class, $plot);
        $this->assertIsArray($plot->data);
        $this->assertEquals(25, count($plot->data));
        $this->assertEquals(976, $plot->data[0]->table_id);
        $this->assertIsArray($plot->getPagination());
        $this->assertEquals(25, count($plot->data));
        $this->assertEquals(25, $plot->getPagination()['limit']);
        $this->assertEquals('desc', $plot->getPagination()['order']);
        $this->assertEquals('table_id', $plot->getPagination()['sort']);
        $this->assertEquals(2, $plot->getPagination()['page']);
        $this->assertEquals(25, $plot->getPagination()['offset']);
        $this->assertEquals(1001, $plot->getPagination()['total']);
    }
}
