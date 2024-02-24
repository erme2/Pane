<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Helpers\StringHelper;
use App\Helpers\StoryHelper;
use PHPUnit\Framework\TestCase;
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
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'Action not found (Action: No existing actionAction)', $e->getMessage());
        }
    }

    public function test_load_story(): void
    {
        $mockRequest = $this->createMockRequest();
        $this->assertInstanceOf('App\Stories\AbstractStory', $this->testClass->loadStory($mockRequest, 'test'));
        try {
            $this->testClass->loadStory($mockRequest,'No existing story');
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'Story not found (Story: No existing storyStory)', $e->getMessage());
        }
    }
}
