<?php

namespace Tests\Feature\Stories;

use App\Exceptions\SystemException;
use App\Stories\StoryPlot;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;

class StoryPlotTest extends TestCase
{
    const ERRORS = [
        'invalid_status_code' => 'App\Stories\StoryPlot::setStatus(): Argument #1 ($status) must be of type int, string given, called in',
    ];

    private $wrongContentType = 'wrong/content-type';
    private $lowInvalidStatusCode = 99;
    private $highInvalidStatusCode = 600;
    private $stringInvalidStatusCode = 'string';


    /**
     * @return void
     * @throws \Exception
     * @covers \App\Stories\StoryPlot::__construct
     */
    public function test__construct()
    {
        // basic (json)
        $plot = new StoryPlot();
        $this->assertInstanceOf(StoryPlot::class, $plot);

        // explicit (json)
        $plot = new StoryPlot('application/json');
        $this->assertInstanceOf(StoryPlot::class, $plot);

        // explicit (xml)
        $this->expectException(SystemException::class);
        $this->expectExceptionMessage("Invalid content type: $this->wrongContentType");
        new StoryPlot($this->wrongContentType);
    }

    public function testSetGetContentType_basic()
    {
        $plot = new StoryPlot();
        $this->assertEquals('application/json', $plot->getContentType());

        // invalid content type
        try {
            $plot->setContentType('application/xml');
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals("System Exception: Invalid content type: application/xml", $e->getMessage());
        }

        // valid content type
        $plot->setContentType('application/json');
        $this->assertEquals('application/json', $plot->getContentType());
    }

    public function testSetGetStatus_basic()
    {
        // empty
        $plot = new StoryPlot();
        $this->assertEquals(0, $plot->getStatus());

        // basic success
        $plot->setStatus(Response::HTTP_OK);
        $this->assertEquals(200, $plot->getStatus());
        $this->assertEquals(StoryPlot::VALID_CONTENT_TYPES['json'], $plot->getContentType());

        // wrong status code (too low)
        try {
            $plot = new StoryPlot();
            $plot->setStatus($this->lowInvalidStatusCode);
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals("System Exception: Invalid status code: {$this->lowInvalidStatusCode}", $e->getMessage());
        }

        // wrong status code (too high)
        try {
            $plot = new StoryPlot();
            $plot->setStatus($this->highInvalidStatusCode);
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals("System Exception: Invalid status code: {$this->highInvalidStatusCode}", $e->getMessage());
        }

        // wrong status code (string)
        try {
            $plot = new StoryPlot();
            $plot->setStatus($this->stringInvalidStatusCode);
        } catch (\Error $e) {
            $this->assertInstanceOf(\Error::class, $e);
            $this->assertEquals(self::ERRORS['invalid_status_code'], substr($e->getMessage(), 0, strlen(self::ERRORS['invalid_status_code'])));
        }
    }
}
