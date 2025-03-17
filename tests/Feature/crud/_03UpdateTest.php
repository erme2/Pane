<?php

namespace Tests\Feature\crud;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use Illuminate\Http\Response;
use Tests\TestCase;

class _03UpdateTest extends TestCase
{
    public function test_wrong_table_update(): void
    {
        $wrongTable = "wrong_table";
        $endpoint = "/crud/$wrongTable/1";
        $response = $this->put($endpoint, ['some' => 'data']);
        $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);
        $result = json_decode($response->getContent(), false);
        $this->assertIsObject($result);
        $this->assertEquals(Response::$statusTexts[$response->getStatusCode()], $result->status);
        $this->assertIsObject($result->data);
        $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX."Table for $wrongTable ($wrongTable) not found", $result->data->message);
    }

    public function test_empty_update()
    {
        $endpoint = '/crud/'.AbstractMapper::TABLES['test_table'].'/1';
        $response = $this->put($endpoint);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $result = json_decode($response->getContent(), false);
        $this->assertEquals(Response::$statusTexts[$response->getStatusCode()], $result->status);
        $this->assertIsObject($result->data);
        $this->assertIsArray($result->data->errors);
        $this->assertCount(4, $result->data->errors);
        foreach ($result->data->errors as $error) {
            $this->assertEquals($error->message, "The ".str_replace('_', ' ', $error->field_name)." field is required.");
        }
    }

    public function test_validating_wrong_data()
    {
        $endpoint = '/crud/'.AbstractMapper::TABLES['test_table'].'/1';
        $response = $this->put($endpoint, self::INVALID_TEST_TABLE_RECORD);
        $result = json_decode($response->getContent(), false);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals(Response::$statusTexts[$response->getStatusCode()], $result->status);
        $this->assertIsObject($result->data);
        $this->assertIsArray($result->data->errors);
        $this->assertCount(9, $result->data->errors);
    }

    public function test_update()
    {
        $endpoint = '/crud/'.AbstractMapper::TABLES['test_table'].'/1';
        $data = self::VALID_TEST_TABLE_RECORD;
        $data['name'] = 'updated test_name '.time();
        $response = $this->put($endpoint, $data);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $result = json_decode($response->getContent(), false);
        $this->assertEquals(Response::$statusTexts[$response->getStatusCode()], $result->status);
        $this->assertIsArray($result->data);
        $this->assertCount(1, $result->data);
        $this->assertIsObject($result->data[0]);
        $this->assertIsInt($result->data[0]->table_id);
        $this->assertGreaterThan(0, $result->data[0]->table_id);

        foreach ($data as $key => $value) {
            switch ($key) {
                case 'test_array':
                    $this->assertIsArray($result->data[0]->$key);
                    $this->assertCount(count($value), $result->data[0]->$key);
                    foreach ($value as $k => $v) {
                        $this->assertEquals($v, $result->data[0]->$key[$k]);
                    }
                    break;
                case 'test_date':
                    $this->assertIsObject($result->data[0]->$key);
                    $this->assertIsString($result->data[0]->$key->date);
                    $this->assertIsInt($result->data[0]->$key->timezone_type);
                    $this->assertIsString($result->data[0]->$key->timezone);
                    break;
                case 'test_json':
                    $this->assertIsObject($result->data[0]->$key);
                    break;
                case 'password':
                    break;
                default:
                    $this->assertEquals($value, $result->data[0]->$key);
            }
        }
    }
}
