<?php

namespace Tests\Feature\Actions;

use App\Actions\DeleteAction;
use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Stories\StoryPlot;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Request as RequestAlias;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;
use Tests\TestCase;

class DeleteActionTest extends TestCase
{
    private StoryPlot $mockStoryPlot;

    private DeleteAction $action;

    private string $table = 'test_table';

    private int $expectedTotal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockStoryPlot = new StoryPlot;
        $this->mockStoryPlot->requestData['method'] = RequestAlias::METHOD_DELETE;
        $this->action = new DeleteAction;
        $this->expectedTotal = DB::table($this->table)->count();
    }

    public function test_with_empty_key(): void
    {
        try {
            $plot = $this->action->exec($this->table, $this->mockStoryPlot);
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals('System Exception: Key is required', $e->getMessage());
            $this->assertEquals(ResponseAlias::HTTP_BAD_REQUEST, $e->getCode());
        }
    }

    public function test_with_invalid_key()
    {
        try {
            $this->action->exec($this->table, $this->mockStoryPlot, 'A');
        } catch (\Exception $e) {
            $this->assertInstanceOf(ValidationException::class, $e);
            $this->assertEquals('Validation failed', $e->getMessage());
            $this->assertEquals(ResponseAlias::HTTP_BAD_REQUEST, $e->getCode());
        }
    }

    public function test_with_wrong_key()
    {
        $plot = $this->action->exec($this->table, $this->mockStoryPlot, 999999);
        $this->assertInstanceOf(StoryPlot::class, $plot);
        $this->assertEquals($plot->getStatus(), ResponseAlias::HTTP_NOT_FOUND);
    }

    public function test_with_valid_key()
    {
        // deleting the last key
        $plot = $this->action->exec($this->table, $this->mockStoryPlot, $this->expectedTotal);
        $this->assertInstanceOf(StoryPlot::class, $plot);
        $this->assertEquals($plot->getStatus(), ResponseAlias::HTTP_NO_CONTENT);
        $updatedTotal = DB::table($this->table)->count();
        $this->assertEquals($this->expectedTotal - 1, $updatedTotal);
    }
}
