<?php

namespace Tests\Unit\Stories;

use App\Exceptions\SystemException;
use App\Stories\StoryPlot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderBag;
use Tests\TestsHelper;

class StoryPlotTest extends TestCase
{
    use TestsHelper;

    const ERRORS = [
        'invalid_status_code' => 'App\Stories\StoryPlot::setStatus(): Argument #1 ($status) must be of type int, string given, called in',
    ];

    private $wrongContentType = 'wrong/content-type';
    private $lowInvalidStatusCode = 99;
    private $highInvalidStatusCode = 600;
    private $stringInvalidStatusCode = 'string';

    private $testRequest = [
        'data' => [
            'test' => 'test',
            1 => 'One',
            2 => 'Two',
            3 => 'Three',
            'more' => [
                'name' => 'test',
                'age' => 99,
                'address' => 'test address',
            ]
        ],
        'headers' =>  [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'test' => 'fake',
        ],
        'method' => Request::METHOD_PATCH, // just a random method
    ];


    /**
     * @covers \App\Stories\StoryPlot::__construct
     *
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

    /**
     * @covers \App\Stories\StoryPlot::getContentType
     * @covers \App\Stories\StoryPlot::setContentType
     *
     * @return void
     * @throws SystemException
     */
    public function testSetGetContentType_basic(): void
    {
        $plot = new StoryPlot();
        $this->assertEquals('application/json', $plot->getContentType());

        // invalid content type
        try {
            $plot->setContentType('application/xml');
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX."Invalid content type: application/xml", $e->getMessage());
        }

        // valid content type
        $plot->setContentType('application/json');
        $this->assertEquals('application/json', $plot->getContentType());
    }

    /**
     * @covers \App\Stories\StoryPlot::getStatus
     * @covers \App\Stories\StoryPlot::setStatus
     *
     * @return void
     * @throws SystemException
     */
    public function testSetGetStatus_basic(): void
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
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX."Invalid status code: {$this->lowInvalidStatusCode}", $e->getMessage());
        }

        // wrong status code (too high)
        try {
            $plot = new StoryPlot();
            $plot->setStatus($this->highInvalidStatusCode);
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX."Invalid status code: {$this->highInvalidStatusCode}", $e->getMessage());
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

    /**
     * @covers \App\Stories\StoryPlot::getHeaters
     * @covers \App\Stories\StoryPlot::setRequestData
     *
     * @return void
     * @throws SystemException
     */
    public function test_set_request_data(): void
    {
        $fakeRequest = $this->createMockRequest(
            '/test',
            $this->testRequest['method'],
            $this->testRequest['data'],
            $this->testRequest['headers']
        );
        $testPlot = new StoryPlot();
        $testPlot->setRequestData($fakeRequest);

        $this->assertEquals($this->testRequest['method'], $testPlot->requestData['method']);
        $this->assertEquals($this->testRequest['data'], $testPlot->requestData['data']);
        $this->assertInstanceOf(HeaderBag::class, $testPlot->getHeaders());
        $this->assertEquals('fake', $testPlot->getHeaders()->get('test'));
    }
}
