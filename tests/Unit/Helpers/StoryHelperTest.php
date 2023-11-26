<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use PHPUnit\Framework\TestCase;
use App\Helpers\StringHelper;
use App\Helpers\StoryHelper;
use Tests\TestsHelper;

class StoryHelperTest extends TestCase
{
    use testsHelper;

    private object $testClass;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->testClass = new class {
            use StringHelper, StoryHelper;
        };
    }

    public function test_load_action(): void
    {
        $this->assertInstanceOf('App\Actions\AbstractAction', $this->testClass->loadAction('test'));
        try {
            $this->testClass->loadAction('No existing action');
        } catch (SystemException $e) {
            $this->assertEquals('System Exception: Action not found (Action: No existing actionAction)', $e->getMessage());
        }
    }

    public function test_load_story(): void
    {
        $mockRequest = $this->createMockRequest();
        $this->assertInstanceOf('App\Stories\AbstractStory', $this->testClass->loadStory($mockRequest, 'test'));
        try {
            $this->testClass->loadStory($mockRequest,'No existing story');
        } catch (SystemException $e) {
            $this->assertEquals('System Exception: Story not found (Story: No existing storyStory)', $e->getMessage());
        }
    }
}
