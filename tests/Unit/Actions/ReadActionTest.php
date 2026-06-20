<?php

namespace Tests\Unit\Actions;

use App\Actions\ReadAction;
use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\DefaultsHelper;
use App\Stories\StoryPlot;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\TestsHelper;

class ReadActionTest extends TestCase
{
    use DefaultsHelper, TestsHelper;

    private StoryPlot $mockStoryPlot;
    private ReadAction $action;
    private $table = 'test_table';
    private int  $expectedTotal;


    /**
     * Set up the test environment before each test.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->mockStoryPlot = new StoryPlot();
        $this->mockStoryPlot->requestData['method'] = 'GET';
        $this->action = new ReadAction();
        $this->expectedTotal = DB::table($this->table)->count();
    }

    /**
     * Run the test for executing the ReadAction with an empty key, which should return the first page of results.
     *
     * @return void
     * @throws SystemException
     * @throws ValidationException
     */
    public function testWithEmptyKey()
    {
        $plot = $this->action->exec($this->table, $this->mockStoryPlot);

        // it should return the first page
        $this->assertInstanceOf(StoryPlot::class, $plot);
        $this->assertIsArray($plot->data);
        $this->assertIsArray($plot->getPagination());
        $this->assertEquals(self::PAGINATION_LIMIT, count($plot->data));
        $this->assertEquals(self::PAGINATION_LIMIT, $plot->getPagination()['limit']);
        $this->assertEquals('asc', $plot->getPagination()['order']);
        $this->assertEquals('table_id', $plot->getPagination()['sort']);
        $this->assertEquals(1, $plot->getPagination()['page']);
        $this->assertEquals(0, $plot->getPagination()['offset']);
        $this->assertEquals($this->expectedTotal, $plot->getPagination()['total']);
    }

    /**
     * Run the test for executing the ReadAction with an invalid key, which should throw a ValidationException.
     *
     * @return void
     */
    public function testWithInvalidKey()
    {
        try {
            $this->action->exec($this->table, $this->mockStoryPlot, 'A');
        } catch (\Exception $e) {
            $this->assertInstanceOf(ValidationException::class, $e);
            $this->assertEquals('Validation failed', $e->getMessage());
            $this->assertEquals(ResponseAlias::HTTP_BAD_REQUEST, $e->getCode());
        }
    }

    /**
     * Run the test for executing the ReadAction with a non-existent key, which should throw a ValidationException.
     *
     * @return void
     * @throws SystemException
     * @throws ValidationException
     */
    public function testWithWrongKey()
    {
        $plot = $this->action->exec($this->table, $this->mockStoryPlot, 999999);
        $this->assertInstanceOf(StoryPlot::class, $plot);
        $this->assertEmpty($plot->data);
        $this->assertEquals($plot->getStatus(), ResponseAlias::HTTP_NOT_FOUND);
    }

    public function testValidKey()
    {
        $key = 1;
        $plot = $this->action->exec($this->table, $this->mockStoryPlot, $key);
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
        $sort = 'table_id';
        $order = 'desc';
        $page = rand(1, 10);
        $offset = self::PAGINATION_LIMIT * ($page - 1);
        $field = 'table_id';
        $randomPage = DB::table($this->table)
            ->select($field)
            ->limit(self::PAGINATION_LIMIT)
            ->offset($offset)
            ->orderBy($sort, $order)
            ->get()->toArray();

        $this->mockStoryPlot->requestData['data'] = [
            'limit' => self::PAGINATION_LIMIT,
            'order' => $order,
            'sort' => $sort,
            'page' => $page,
        ];
        $plot = $this->action->exec($this->table, $this->mockStoryPlot);
        // it should return the first page
        $this->assertInstanceOf(StoryPlot::class, $plot);
        $this->assertIsArray($plot->data);
        $this->assertEquals(self::PAGINATION_LIMIT, count($plot->data));
        $this->assertEquals($randomPage[0]->{$field}, $plot->data[0]->table_id);
        $this->assertIsArray($plot->getPagination());
        $this->assertEquals(self::PAGINATION_LIMIT, count($plot->data));
        $this->assertEquals(self::PAGINATION_LIMIT, $plot->getPagination()['limit']);
        $this->assertEquals($order, $plot->getPagination()['order']);
        $this->assertEquals($field, $plot->getPagination()['sort']);
        $this->assertEquals($page, $plot->getPagination()['page']);
        $this->assertEquals($offset, $plot->getPagination()['offset']);
        $this->assertEquals($this->expectedTotal, $plot->getPagination()['total']);
    }
}
