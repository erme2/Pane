<?php

namespace Tests\Unit\Stories;

use PHPUnit\Framework\TestCase;
use Tests\TestsHelper;
use App\Stories\AbstractStory;


class AbstractStoryTest extends TestCase
{
    use TestsHelper;

    public function test_create(): void
    {
        $testStory = new class($this->createMockRequest()) extends AbstractStory {
            public array $actions = ['Test'];
        };
        $this->assertInstanceOf('App\Stories\AbstractStory', $testStory);
        $this->assertInstanceOf('App\Stories\StoryPlot', $testStory->plot);
    }

    public function test_run(): void
    {
        $testStory = new class($this->createMockRequest()) extends AbstractStory {
            public array $actions = ['Test'];
        };
        $storyPlot = $testStory->run('test');
        $this->assertInstanceOf('App\Stories\StoryPlot', $storyPlot);
        $this->markTestIncomplete();
    }
}
