<?php

namespace Tests\Unit\Stories;

use App\Exceptions\SystemException;
use App\Stories\CrudStory;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Tests\TestsHelper;

class CrudStoryTest extends TestCase
{
    use TestsHelper;

    public function test_create(): void
    {
        // create
        $testCrudStory = new class($this->createMockRequest('/crud/test', Request::METHOD_POST)) extends CrudStory {};
        $this->assertInstanceOf('App\Stories\CrudStory', $testCrudStory);
        $this->assertInstanceOf('App\Stories\StoryPlot', $testCrudStory->plot);
        $this->assertEquals(['validate', 'save'], $testCrudStory->actions);
        // read
        $testCrudStory = new class($this->createMockRequest('/crud/test', Request::METHOD_GET)) extends CrudStory {};
        $this->assertInstanceOf('App\Stories\CrudStory', $testCrudStory);
        $this->assertInstanceOf('App\Stories\StoryPlot', $testCrudStory->plot);
        $this->assertEquals(['read'], $testCrudStory->actions);
        // update
        $testCrudStory = new class($this->createMockRequest('/crud/test', Request::METHOD_PUT)) extends CrudStory {};
        $this->assertInstanceOf('App\Stories\CrudStory', $testCrudStory);
        $this->assertInstanceOf('App\Stories\StoryPlot', $testCrudStory->plot);
        $this->assertEquals(['validate', 'save'], $testCrudStory->actions);
        // delete
        $testCrudStory = new class($this->createMockRequest('/crud/test', Request::METHOD_DELETE)) extends CrudStory {};
        $this->assertInstanceOf('App\Stories\CrudStory', $testCrudStory);
        $this->assertInstanceOf('App\Stories\StoryPlot', $testCrudStory->plot);
        $this->assertEquals(['delete'], $testCrudStory->actions);

        // other
        try {
            $testCrudStory = new class($this->createMockRequest('/crud/test', Request::METHOD_OPTIONS)) extends CrudStory {};
        } catch (SystemException $e) {
            $search = "System Exception: Method not allowed (method: OPTIONS object: App\Stories\CrudStory";
            $this->assertStringContainsString($search, $e->getMessage(), 'test_create: OPTIONS');
        }

    }
}
