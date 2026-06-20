<?php

namespace Tests\Feature\crud;

use App\Exceptions\SystemException;
use App\Helpers\DefaultsHelper;
use App\Mappers\AbstractMapper;
use Database\Seeders\TestTableSeeder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class _02ReadTest extends TestCase
{
    use DefaultsHelper;
    public string $endpoint = '/crud/';
    private $table = 'test_table';

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
        $recordID = 1;
        $response = $this->get("$this->endpoint$this->table/$recordID");
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

    public function test_read_deleted_record(): void
    {
        $recordID = 999999999999;
        $response = $this->get("$this->endpoint$this->table/$recordID");
        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_pagination()
    {
        $params = [
            'page' => 1,
            'limit' => 10,
        ];
        $response = $this->get("$this->endpoint$this->table?".http_build_query($params));
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
        $check = $this->sort_check($content->data, 'table_id');
        $this->assertSame($check['sorted'], $check['rows']);
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
        $check = $this->sort_check($content->data, 'table_id', false);
        $this->assertSame($check['sorted'], $check['rows']);
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
        $check = $this->sort_check($content->data, 'table_id');
        $this->assertSame($check['sorted'], $check['rows']);
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
        $check = $this->sort_check($content->data, 'email');
        $this->assertSame($check['sorted'], $check['rows']);
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
        $check = $this->sort_check($content->data, 'email', false);
        $this->assertSame($check['sorted'], $check['rows']);
        $this->assertEquals($content->pagination->page, $params['page']);
        $this->assertEquals($content->pagination->limit, $params['limit']);
    }

    public function test_pagination_filter()
    {
        $totalRecords = DB::table($this->table)->count();
        $totalPages = ceil($totalRecords / self::PAGINATION_LIMIT);

        // default limit and pagination
        $params = [];
        $response = $this->get("$this->endpoint$this->table?".http_build_query($params));
        $content = json_decode($response->getContent(), false);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals(self::PAGINATION_LIMIT, count($content->data));
        $this->assertEquals(1, $content->data[0]->table_id);
        $this->assertEquals(1, $content->pagination->page);
        $this->assertEquals(self::PAGINATION_LIMIT, $content->pagination->limit);
        $this->assertEquals(self::PAGINATION_OFFSET, $content->pagination->offset);
        $this->assertEquals($totalRecords, $content->pagination->total);
        $this->assertEquals($totalPages, $content->pagination->pages);

        // basic limit and pagination
        $params = [
            'page' => 1,
            'limit' => 10,
        ];
        $totalPages = ceil($totalRecords / $params['limit']);
        $response = $this->get("$this->endpoint$this->table?".http_build_query($params));
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), false);
        $this->assertEquals($params['limit'], count($content->data));
        $this->assertEquals(1, $content->data[0]->table_id);
        $this->assertEquals($params['page'], $content->pagination->page);
        $this->assertEquals($params['limit'], $content->pagination->limit);

        $this->assertEquals($totalRecords, $content->pagination->total);
        $this->assertEquals($totalPages, $content->pagination->pages);

        // a random page on a 10 rows limit
        $params = [
            'page' => rand(10, 25),
            'limit' => 10,
        ];
        $totalPages = ceil($totalRecords / $params['limit']);
        $response = $this->get("$this->endpoint$this->table?".http_build_query($params));
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), false);
        $this->assertEquals($params['limit'], count($content->data));
        $this->assertEquals($params['page'], $content->pagination->page);
        $this->assertEquals($params['limit'], $content->pagination->limit);
        $this->assertEquals($totalRecords, $content->pagination->total);
        $this->assertEquals($totalPages, $content->pagination->pages);


        // reverting the order
        $params = [
            'page' => 1,
            'limit' => 10,
            'order' => 'desc'
        ];
        $lastID = DB::table($this->table)->select()->max('table_id');
        $totalPages = ceil($totalRecords / $params['limit']);
        $response = $this->get("$this->endpoint$this->table?".http_build_query($params));
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $content = json_decode($response->getContent(), false);
        $this->assertEquals($params['limit'], count($content->data));
        $this->assertEquals($lastID, $content->data[0]->table_id);
        $this->assertEquals($params['page'], $content->pagination->page);
        $this->assertEquals($params['limit'], $content->pagination->limit);
        $this->assertEquals($totalRecords, $content->pagination->total);
        $this->assertEquals($totalPages, $content->pagination->pages);

        // Test wrong sort field
        $params = [
            'page' => 1,
            'limit' => 10,
            'sort' => 'nonexistent_field',
        ];
        $response = $this->get("$this->endpoint$this->table?".http_build_query($params));
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        // Test wrong sort order
        $params = [
            'page' => 1,
            'limit' => 10,
            'order' => 'invalid_order',
        ];
        $response = $this->get("$this->endpoint$this->table?".http_build_query($params));
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        // Test wrong page (negative)
        $params = [
            'page' => -1,
            'limit' => 10,
        ];
        $response = $this->get("$this->endpoint$this->table?".http_build_query($params));
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());        // Test wrong sort field

        $params = [
            'page' => 1,
            'limit' => 10,
            'sort' => 'nonexistent_field',
        ];
        $response = $this->get("$this->endpoint$this->table?".http_build_query($params));
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        // Test wrong sort order
        $params = [
            'page' => 1,
            'limit' => 10,
            'order' => 'invalid_order',
        ];
        $response = $this->get("$this->endpoint$this->table?".http_build_query($params));
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        // Test wrong limit (negative)
        $params = [
            'page' => 34,
            'limit' => -10,
        ];
        $response = $this->get("$this->endpoint$this->table?".http_build_query($params));
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    function sort_check(array $data, string $field, bool $asc = true): array {
        $rows = array_map(fn ($row) => $row->{$field}, $data);
        $sorted = $rows;
        if ($asc) {
            sort($sorted, SORT_NUMERIC);
        } else {
            rsort($sorted, SORT_NUMERIC);
        }
        return ['sorted' => $sorted, 'rows' => $rows];
    }
}
