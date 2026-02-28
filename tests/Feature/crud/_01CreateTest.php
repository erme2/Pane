<?php

namespace Tests\Feature\crud;

use App\Exceptions\SystemException;
use App\Mappers\AbstractMapper;
use Illuminate\Http\Response;
use Tests\TestCase;
use Illuminate\Support\Str;

class _01CreateTest extends TestCase
{

    public function test_wrong_table(): void
    {
        // empty call
        $wrongTable = "wrong_table";
        $endpoint = "/crud/$wrongTable";
        $response = $this->post($endpoint, ['some' => 'data']);
        $response->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);
        $result = json_decode($response->getContent(), false);
        $this->assertIsObject($result);
        $this->assertEquals(Response::$statusTexts[$response->getStatusCode()], $result->status);
        $this->assertIsObject($result->data);
        $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX."Table for $wrongTable ($wrongTable) not found", $result->data->message);
    }

    public function test_empty_create()
    {
        // empty call
        $endpoint = '/crud/'.AbstractMapper::TABLES['test_table'];
        $response = $this->post($endpoint);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $result = json_decode($response->getContent(), false);
        $this->assertEquals(Response::$statusTexts[$response->getStatusCode()], $result->status);
        $this->assertIsObject($result->data);
        $this->assertIsArray($result->data->errors);
        $this->assertCount(3, $result->data->errors);
    }

    public function test_wrong_create()
    {
        // empty call
        $endpoint = '/crud/'.AbstractMapper::TABLES['test_table'];
        $response = $this->post($endpoint, self::INVALID_TEST_TABLE_RECORD);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $result = json_decode($response->getContent(), false);
        $this->assertEquals(Response::$statusTexts[$response->getStatusCode()], $result->status);
        $this->assertIsObject($result->data);
        $this->assertIsArray($result->data->errors);
        $this->assertCount(9, $result->data->errors);
    }

    public function test_create_ok()
    {
        // valid create call
        $endpoint = '/crud/'.AbstractMapper::TABLES['test_table'];
        $data = self::VALID_TEST_TABLE_RECORD;
        $suffix = (string) Str::uuid();
        $data['name'] = "unique test_name $suffix";
        $data['email'] = "test.$suffix@email.com";
        $response = $this->post($endpoint, $data);
        $result = json_decode($response->getContent(), false);
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
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
                    $this->assertEquals(AbstractMapper::PASSWORD_REPLACEMENT, $result->data[0]->$key);
                    break;
                default:
                    $this->assertEquals($value, $result->data[0]->$key);
            }
        }
    }
}
