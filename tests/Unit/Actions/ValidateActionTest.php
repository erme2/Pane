<?php

namespace Tests\Unit\Actions;

use App\Actions\ValidateAction;
use App\Exceptions\ValidationException;
use App\Stories\StoryPlot;
use Tests\TestCase;
use Tests\TestsHelper;

class ValidateActionTest extends TestCase
{
    use TestsHelper;
    private StoryPlot $mockStoryPlot;
    private ValidateAction $action;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->mockStoryPlot = new StoryPlot();
        $this->action = new ValidateAction();
    }

    public function test_exec_empty_data(): void
    {
        // empty data
        try {
            $plot = $this->action->exec('TestTable', $this->mockStoryPlot);
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertIsArray($errors);
            $this->assertCount(4, $errors);

            foreach ($errors as $error) {
                $this->assertIsArray($error);
                $this->assertArrayHasKey('field_name', $error);
                $this->assertArrayHasKey('message', $error);
                $this->assertStringContainsString(str_replace('_', ' ', $error['field_name']), $error['message']);
                $this->assertStringContainsString('required', $error['message']);
            }
        }
    }

//    public function test_exec_errors_1(): void
//    {
//
//        // wrong data
//        $testStoryPlot->requestData['method'] = Request::METHOD_POST;
//$testStoryPlot->requestData['data'] = [
//    'name' => '', // empty string
//    'description' => ['test'], // wrong format
//    'is_active' => 'test', // wrong format
//    'created_at' => 'test', // invalid date
//    'test_array' => 'test', // string instead of array
//    'password' => '123456', // too short
//    'email' => 'test', // invalid email
//    'test_json' => 'test', // invalid json
//];
//print_R($testStoryPlot->requestData);
//        try {
//            $plot = $action->exec('TestTable', $testStoryPlot);
//        } catch (ValidationException $e) {
//print_R($errors);
//            $errors = $e->getErrors();
//            // name is empty
//            $this->assertEquals('Name is required'.self::CHECK_ERROR_MESSAGES, $errors[0]['message']);
//            $this->assertEquals('Test Array must be an array'.self::CHECK_ERROR_MESSAGES, $errors[1]['message']);
//die("AZAZA");
//        } catch (\Exception $e) {
//die("@ ERROR ".$e->getMessage());
//        }
//    }
}
