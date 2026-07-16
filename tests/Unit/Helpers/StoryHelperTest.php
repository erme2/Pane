<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Helpers\StoryHelper;
use App\Helpers\StringHelper;
use PHPUnit\Framework\TestCase;
use Tests\TestsHelper;

class StoryHelperTest extends TestCase
{
    use TestsHelper;

    private object $testClass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testClass = new class
        {
            use StoryHelper, StringHelper;
        };
    }

    public function test_load_action(): void
    {
        $this->assertInstanceOf('App\Actions\ReadAction', $this->testClass->loadAction('read'));
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
        $this->assertInstanceOf('App\Stories\CrudStory', $this->testClass->loadStory($mockRequest, 'crud'));
        try {
            $this->testClass->loadStory($mockRequest, 'No existing story');
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'Story not found (Story: No existing storyStory)', $e->getMessage());
        }
    }
}
