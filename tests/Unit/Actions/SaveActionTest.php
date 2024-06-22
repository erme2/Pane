<?php

namespace Tests\Unit\Actions;

use App\Actions\SaveAction;
use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use App\Stories\StoryPlot;
use Illuminate\Http\Response;
use Tests\TestCase;
use Tests\TestsHelper;

class SaveActionTest extends TestCase
{
    use TestsHelper;
    private StoryPlot $mockStoryPlot;
    private SaveAction $action;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->mockStoryPlot = new StoryPlot();
        $this->action = new SaveAction();
    }

    public function test_empty_data_exec(): void
    {
        try {
            $this->action->exec('TestTable', $this->mockStoryPlot);
        } catch (\Exception $e) {
            $this->assertInstanceOf(SystemException::class, $e);
            $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX.'No data to save', $e->getMessage());
            $this->assertEquals(Response::HTTP_NO_CONTENT, $e->getCode());
        }
    }

    public function test_create(): void
    {
        $this->mockStoryPlot->requestData['data'] = self::VALID_TEST_TABLE_RECORD;
        $this->mockStoryPlot->options['is_new_record'] = true;
        $plot = $this->action->exec(self::TEST_TABLE_NAME, $this->mockStoryPlot);

        $this->assertInstanceOf(StoryPlot::class, $plot);
        $this->assertIsArray($plot->data);
        $this->assertIsObject($plot->data[0]);

        foreach (self::VALID_TEST_TABLE_RECORD as $key => $value) {
            switch ($key) {
                case 'test_date':
                    $this->assertEquals($value, $plot->data[0]->$key->format('d-m-Y'));
                    break;
                case 'password': // password should not be returned
                    $this->assertEquals(AbstractMapper::PASSWORD_REPLACEMENT, $plot->data[0]->$key);
                    break;
                case 'test_json':
                    $this->assertEquals(json_decode($value), $plot->data[0]->$key);
                    break;
                default:
                    $this->assertEquals($value, $plot->data[0]->$key);
            }
        }
    }

    public function test_edit(): void
    {
        $this->mockStoryPlot->requestData['data'] = self::UPDATED_VALID_TEST_TABLE_RECORD;
        $this->mockStoryPlot->options['is_new_record'] = false;
        $plot = $this->action->exec(self::TEST_TABLE_NAME, $this->mockStoryPlot);

        $this->assertInstanceOf(StoryPlot::class, $plot);
        $this->assertIsArray($plot->data);
        $this->assertIsObject($plot->data[0]);

        foreach (self::UPDATED_VALID_TEST_TABLE_RECORD as $key => $value) {
            switch ($key) {
                case 'test_date':
                    $this->assertEquals($value, $plot->data[0]->$key->format('d-m-Y'));
                    break;
                case 'password': // password should not be returned
                    $this->assertEquals(AbstractMapper::PASSWORD_REPLACEMENT, $plot->data[0]->$key);
                    break;
                case 'test_json':
                    $this->assertEquals(json_decode($value), $plot->data[0]->$key);
                    break;
                default:
                    $this->assertEquals($value, $plot->data[0]->$key);
            }
        }
    }
}
