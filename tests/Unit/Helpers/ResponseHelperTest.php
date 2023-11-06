<?php

namespace Tests\Unit\Helpers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class ResponseHelperTest extends \Tests\TestCase
{
    private string $testError = 'Test Error';
    private int $testErrorCode = 789;
    private array $testData = [
        'test' => [
            1 => 'one',
            2 => 'two',
            3 => 'three',
        ],
    ];


    /**
     * @covers \App\Helpers\ResponseHelper::__construct
     * @covers \App\Helpers\ResponseHelper::getResponse
     *
     * @return void
     */
    public function test_construct()
    {
        $testController = new class() extends Controller {
            use ResponseHelper;
        };
        $this->assertInstanceOf(Controller::class, $testController);
        $this->assertInstanceOf(\Illuminate\Http\Response::class, $testController->getResponse());
    }

    /**
     * @covers \App\Helpers\ResponseHelper::getStatusText
     *
     * @return void
     */
    public function test_get_status_text()
    {
        $testController = new class() extends Controller {
            use ResponseHelper;
        };
        $this->assertEquals('OK', $testController->getStatusText(200));
        $this->assertEquals('Not Found', $testController->getStatusText(404));
        $this->assertEquals('', $testController->getStatusText(999));
    }

    /**
     * @covers \App\Helpers\ResponseHelper::success
     *
     * @return void
     */
    public function test_success()
    {
        $testController = new class() extends Controller {
            use ResponseHelper;
        };
        $storyPlot = new \App\Stories\StoryPlot();
        $storyPlot->setStatus(Response::HTTP_OK);
        $storyPlot->data = $this->testData;
        $testResponse = $testController->success($storyPlot);
        $this->assertInstanceOf(\Illuminate\Http\Response::class, $testResponse);
        $this->assertEquals($testResponse->getStatusCode(), Response::HTTP_OK);
        $this->assertJson($testResponse->getContent());
        $data = json_decode($testResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $this->assertIsArray($data['data']);
        $this->assertEquals($this->testData, $data['data']);
        $this->assertEquals($testController->getStatusText(Response::HTTP_OK), $data['status']);
    }

    public function test_error()
    {
        $testController = new class() extends Controller {
            use ResponseHelper;
        };
        // standard http error
        $error = new \Exception($this->testError, Response::HTTP_NOT_FOUND);
        $testResponse = $testController->error($error);
        $this->assertInstanceOf(\Illuminate\Http\Response::class, $testResponse);
        $this->assertEquals($testResponse->getStatusCode(), Response::HTTP_NOT_FOUND);
        $this->assertJson($testResponse->getContent());
        $data = json_decode($testResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $this->assertIsArray($data['data']);
        $this->assertEquals($this->testError, $data['data']['message']);
        $this->assertEquals($testController->getStatusText(Response::HTTP_NOT_FOUND), $data['status']);

        // non standard status code error
        $error = new \Exception($this->testError, $this->testErrorCode);
        $testResponse = $testController->error($error);
        $this->assertInstanceOf(\Illuminate\Http\Response::class, $testResponse);
        $this->assertEquals($testResponse->getStatusCode(), Response::HTTP_INTERNAL_SERVER_ERROR);
        $this->assertJson($testResponse->getContent());
        $data = json_decode($testResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $this->assertIsArray($data['data']);
        $this->assertEquals($this->testError, $data['data']['message']);
        $this->assertEquals($testController->getStatusText(Response::HTTP_INTERNAL_SERVER_ERROR), $data['status']);
    }
}
