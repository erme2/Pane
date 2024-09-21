<?php

namespace Tests\Feature\crud;

use App\Exceptions\SystemException;
use App\Helpers\DefaultsHelper;
use App\Mappers\AbstractMapper;
use Database\Seeders\TestTableSeeder;
use Illuminate\Http\Response;
use Tests\TestCase;

class ReadTest extends TestCase
{
    use DefaultsHelper;
    public string $endpoint = '/crud/';

    public function test_empty()
    {
        $response = $this->get($this->endpoint);
        $content = json_decode($response->getContent(), false);

        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertEquals('Error', $content->status);
        $this->assertEquals('The route crud could not be found.', $content->data->message);
    }

    public function test_wrong_table()
    {
        $wrongTable = 'wrong_table';
        $response = $this->get($this->endpoint.$wrongTable);
        $content = json_decode($response->getContent(), false);
        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertEquals('Internal Server Error', $content->status);
        $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX."Table for $wrongTable ($wrongTable) not found", $content->data->message);
    }

    public function test_read_static_record(): void
    {
        $response = $this->get($this->endpoint.'test_table/1');
        $content = json_decode($response->getContent(), false);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('OK', $content->status);
        $this->assertIsArray($content->data);
        $this->assertEquals( 1, count($content->data));
        foreach (TestTableSeeder::getStaticRecords()[0] as $key => $value) {
            switch ($key) {
                    case 'test_date':
                        $value = new \DateTime($value);
                        foreach ($value as $k => $v) {
                            $this->assertEquals($v, $content->data[0]->$key->$k);
                        }
                        break;
                    case 'password':
                        $this->assertEquals(AbstractMapper::PASSWORD_REPLACEMENT, $content->data[0]->$key);
                        break;
                    default:
                        $this->assertEquals($value, $content->data[0]->$key);
                }
        }
    }

    public function test_pagination()
    {
        $params = [
            'page' => 1,
            'limit' => 10,
        ];
        $response = $this->get($this->endpoint.'test_table?'.http_build_query($params));
        $content = json_decode($response->getContent(), false);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('OK', $content->status);
        $this->assertIsArray($content->data);
        $this->assertIsObject($content->pagination);
        $this->assertEquals( $params['limit'], count($content->data));
        $this->assertEquals($content->data[0]->table_id, 1);
        $this->assertEquals($content->data[1]->table_id, 2);
        $this->assertEquals($content->pagination->page, $params['page']);
        $this->assertEquals($content->pagination->limit, $params['limit']);

        $params = [
            'page' => 10,
            'limit' => 10,
        ];
        $response = $this->get($this->endpoint.'test_table?'.http_build_query($params));
        $content = json_decode($response->getContent(), false);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('OK', $content->status);
        $this->assertIsArray($content->data);
        $this->assertIsObject($content->pagination);
        $this->assertEquals( $params['limit'], count($content->data));
        $this->assertEquals($content->data[0]->table_id, 91);
        $this->assertEquals($content->data[1]->table_id, 92);
        $this->assertEquals($content->pagination->page, $params['page']);
        $this->assertEquals($content->pagination->limit, $params['limit']);

        $params = [
            'page' => 10,
            'limit' => 10,
            'order' => 'desc'
        ];
        $response = $this->get($this->endpoint.'test_table?'.http_build_query($params));
        $content = json_decode($response->getContent(), false);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('OK', $content->status);
        $this->assertIsArray($content->data);
        $this->assertIsObject($content->pagination);
        $this->assertEquals( $params['limit'], count($content->data));
        $this->assertEquals($content->data[0]->table_id, 913);
        $this->assertEquals($content->data[1]->table_id, 912);
        $this->assertEquals($content->pagination->page, $params['page']);
        $this->assertEquals($content->pagination->limit, $params['limit']);

        $params = [
            'page' => 10,
            'limit' => 10,
            'order' => 'asc'
        ];
        $response = $this->get($this->endpoint.'test_table?'.http_build_query($params));
        $content = json_decode($response->getContent(), false);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('OK', $content->status);
        $this->assertIsArray($content->data);
        $this->assertIsObject($content->pagination);
        $this->assertEquals( $params['limit'], count($content->data));
        $this->assertEquals($content->data[0]->table_id, 91);
        $this->assertEquals($content->data[1]->table_id, 92);
        $this->assertEquals($content->pagination->page, $params['page']);
        $this->assertEquals($content->pagination->limit, $params['limit']);

        $params = [
            'page' => 10,
            'limit' => 10,
            'order' => 'asc',
            'sort' => 'email',
        ];
        $response = $this->get($this->endpoint.'test_table?'.http_build_query($params));
        $content = json_decode($response->getContent(), false);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('OK', $content->status);
        $this->assertIsArray($content->data);
        $this->assertIsObject($content->pagination);
        $this->assertEquals( $params['limit'], count($content->data));
        $this->assertEquals($content->data[0]->table_id, 183);
        $this->assertEquals($content->data[1]->table_id, 184);
        $this->assertEquals($content->pagination->page, $params['page']);
        $this->assertEquals($content->pagination->limit, $params['limit']);

        $params = [
            'page' => 10,
            'limit' => 10,
            'order' => 'desc',
            'sort' => 'email',
        ];
        $response = $this->get($this->endpoint.'test_table?'.http_build_query($params));
        $content = json_decode($response->getContent(), false);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('OK', $content->status);
        $this->assertIsArray($content->data);
        $this->assertIsObject($content->pagination);
        $this->assertEquals( $params['limit'], count($content->data));
        $this->assertEquals($content->data[0]->table_id, 923);
        $this->assertEquals($content->data[1]->table_id, 922);
        $this->assertEquals($content->pagination->page, $params['page']);
        $this->assertEquals($content->pagination->limit, $params['limit']);
    }

    public function test_pagination_filter()
    {
        // default limit and pagination
        $params = [];
        $response = $this->get($this->endpoint.'test_table?'.http_build_query($params));
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), false);
        $this->assertEquals(self::PAGINATION_LIMIT, count($content->data));
        $this->assertEquals(1, $content->data[0]->table_id);
        $this->assertEquals(1, $content->pagination->page);
        $this->assertEquals(self::PAGINATION_LIMIT, $content->pagination->limit);
        $this->assertEquals(self::PAGINATION_OFFSET, $content->pagination->offset);
        $this->assertEquals(1003, $content->pagination->total);
        $this->assertEquals(41, $content->pagination->pages);

        // basic limit and pagination
        $params = [
            'page' => 1,
            'limit' => 10,
        ];
        $response = $this->get($this->endpoint.'test_table?'.http_build_query($params));
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), false);
        $this->assertEquals($params['limit'], count($content->data));
        $this->assertEquals(1, $content->data[0]->table_id);
        $this->assertEquals($params['page'], $content->pagination->page);
        $this->assertEquals($params['limit'], $content->pagination->limit);
        $this->assertEquals(1003, $content->pagination->total);
        $this->assertEquals(101, $content->pagination->pages);

        // a random page 101 on a 10 rows limit
        $params = [
            'page' => 101,
            'limit' => 10,
        ];
        $response = $this->get($this->endpoint.'test_table?'.http_build_query($params));
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), false);
        $this->assertEquals(3, count($content->data));
        $this->assertEquals(1001, $content->data[0]->table_id);
        $this->assertEquals($params['page'], $content->pagination->page);
        $this->assertEquals($params['limit'], $content->pagination->limit);
        $this->assertEquals(1003, $content->pagination->total);
        $this->assertEquals(101, $content->pagination->pages);

        // page 1 on a 25 rows limit
        $params = [
            'page' => 1,
            'limit' => 25,
        ];
        $response = $this->get($this->endpoint.'test_table?'.http_build_query($params));
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), false);
        $this->assertEquals($params['limit'], count($content->data));
        $this->assertEquals(1, $content->data[0]->table_id);
        $this->assertEquals($params['page'], $content->pagination->page);
        $this->assertEquals($params['limit'], $content->pagination->limit);
        $this->assertEquals(1003, $content->pagination->total);
        $this->assertEquals(41, $content->pagination->pages);


        // page reverting the order
        $params = [
            'page' => 1,
            'limit' => 10,
            'order' => 'desc'
        ];
        $response = $this->get($this->endpoint.'test_table?'.http_build_query($params));
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), false);
        $this->assertEquals($params['limit'], count($content->data));
        $this->assertEquals(1003, $content->data[0]->table_id);
        $this->assertEquals($params['page'], $content->pagination->page);
        $this->assertEquals($params['limit'], $content->pagination->limit);
        $this->assertEquals(1003, $content->pagination->total);
        $this->assertEquals(101, $content->pagination->pages);

        // test max limit error
        // test wrong sort field
        // test wrong sort order

        $this->markTestIncomplete();
    }
}
