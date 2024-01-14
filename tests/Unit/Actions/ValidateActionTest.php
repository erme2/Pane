<?php

namespace Tests\Unit\Actions;

use App\Actions\ValidateAction;
use App\Exceptions\ValidationException;
use App\Stories\StoryPlot;
use Illuminate\Http\Request;
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

    public function test_exec_error_1(): void
    {
        $this->mockStoryPlot->options['is_new_record'] = true;
        $this->mockStoryPlot->requestData['data']['name'] = ''; // empty name
        $this->mockStoryPlot->requestData['data']['description'] = [1 => 2]; // description is not string
        $this->mockStoryPlot->requestData['data']['is_active'] = (object) ['aa']; // not boolean is_active
        $this->mockStoryPlot->requestData['data']['test_date'] = 'not a date'; // test_date is not a valid date
        $this->mockStoryPlot->requestData['data']['test_array'] = 'not an array'; // test_array is not array
        $this->mockStoryPlot->requestData['data']['password'] = '123'; // short password
        $this->mockStoryPlot->requestData['data']['email'] = 'not an email'; // email is not valid
        $this->mockStoryPlot->requestData['data']['test_json'] = 'not json'; // test_array is not array

        try {
            $plot = $this->action->exec('TestTable', $this->mockStoryPlot);
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            // no errors on the primary key
            foreach ($errors as $error) {
                $this->assertIsArray($error);
                $this->assertArrayHasKey('field_name', $error);
                $this->assertArrayHasKey('message', $error);
                $this->assertNotEquals('table_id', $error['field_name']);
            }
            $this->assertCount(8, $errors);
            // name is empty
            $this->assertEquals("The name field is required.", $errors[0]['message']);
            // description is not string
            $this->assertEquals("The description field must be a string.", $errors[1]['message']);
            // is_active is not boolean
            $this->assertEquals("The is active field must be true or false.", $errors[2]['message']);
            // test_date is not a valid date
            $this->assertEquals("The test date field must be a valid date.", $errors[3]['message']);
            // test_array is required
            $this->assertEquals("The test array field must be an array.", $errors[4]['message']);
            // password
            $this->assertEquals("Password must be at least 8 characters long (this is required to test error messages)", $errors[5]['message']);
            // email
            $this->assertEquals("Email must be a valid email address (this is required to test error messages)", $errors[6]['message']);
            // test_json
            $this->assertEquals("The test json field must be a valid JSON string.", $errors[7]['message']);
        }
    }

    public function test_exec_ok(): void
    {
        $this->mockStoryPlot->options['is_new_record'] = true;
        $this->mockStoryPlot->requestData['data']['name'] = 'good name';
        $this->mockStoryPlot->requestData['data']['description'] = 'a short but good description';
        $this->mockStoryPlot->requestData['data']['is_active'] = true;
        $this->mockStoryPlot->requestData['data']['test_date'] = date('Y-m-d H:i:s');
        $this->mockStoryPlot->requestData['data']['test_array'] = ['test' => 'array'];
        $this->mockStoryPlot->requestData['data']['password'] = '1234567890';
        $this->mockStoryPlot->requestData['data']['email'] = 'test@email.com';
        $this->mockStoryPlot->requestData['data']['test_json'] = '{"test":"json"}';


        $plot = $this->action->exec('TestTable', $this->mockStoryPlot);
        // no errors :) we are happy :)
        $this->assertInstanceOf(StoryPlot::class, $plot);
    }
}
