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

    public function test_exec_error_1(): void
    {
        $this->mockStoryPlot->options['is_new_record'] = true;
        $this->mockStoryPlot->requestData['data'] = self::INVALID_TEST_TABLE_RECORD;

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

            $this->assertCount(9, $errors);
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
            $this->assertEquals("The email field must be a valid email address.", $errors[6]['message']);
            // test_json
            $this->assertEquals("The test json field must be a valid JSON string.", $errors[7]['message']);
            // numero
            $this->assertEquals("The numero field must be at least 10.", $errors[8]['message']);
        }
    }

    public function test_exec_ok(): void
    {
        $this->mockStoryPlot->options['is_new_record'] = true;
        $this->mockStoryPlot->requestData['data'] = self::VALID_TEST_TABLE_RECORD;
        $this->mockStoryPlot->requestData['data']['name'] = 'just another test name';
        $this->mockStoryPlot->requestData['data']['email'] = 'unique@email.com';

        $plot = $this->action->exec('TestTable', $this->mockStoryPlot);
        // no errors :) we are happy :)
        $this->assertInstanceOf(StoryPlot::class, $plot);
    }
}
