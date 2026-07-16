<?php

namespace Tests\Unit\Stories;

use App\Stories\AbstractStory;
use App\Stories\StoryPlot;
use PHPUnit\Framework\TestCase;
use Tests\TestsHelper;

class AbstractStoryTest extends TestCase
{
    use TestsHelper;

    public function test_construct(): void
    {
        $testStory = new class($this->createMockRequest()) extends AbstractStory {};

        $this->assertInstanceOf(AbstractStory::class, $testStory);
        $this->assertInstanceOf(StoryPlot::class, $testStory->plot);
    }

    public function test_run_returns_plot_when_story_has_no_actions(): void
    {
        $testStory = new class($this->createMockRequest()) extends AbstractStory {};

        $storyPlot = $testStory->run('test');

        $this->assertSame($testStory->plot, $storyPlot);
    }
}
