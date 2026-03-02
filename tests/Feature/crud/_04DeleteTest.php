<?php

namespace Tests\Feature\crud;

use App\Exceptions\SystemException;
use App\Helpers\DefaultsHelper;
use Illuminate\Http\Response;
use Tests\TestCase;

class _04DeleteTest extends TestCase
{
    use DefaultsHelper;
    public string $endpoint = '/crud/';

    public function test_empty_delete()
    {
        $response = $this->delete($this->endpoint);
        $content = json_decode($response->getContent(), false);

        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertEquals('Error', $content->status);
        $this->assertEquals('The route crud could not be found.', $content->data->message);
    }

    public function test_wrong_table_delete()
    {
        $wrongTable = 'wrong_table';
        $response = $this->delete("$this->endpoint$wrongTable/1");
        $content = json_decode($response->getContent(), false);

        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertEquals('Internal Server Error', $content->status);
        $this->assertEquals(SystemException::ERROR_MESSAGE_PREFIX."Table for $wrongTable ($wrongTable) not found", $content->data->message);
    }

    public function test_delete_static_record(): void
    {
        $response = $this->delete($this->endpoint.'test_table/2');
        $content = json_decode($response->getContent(), false);
        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->assertNull($content);
    }

    public function test_delete_nonexistent_record(): void
    {
        $nonExistentId = 99999;
        $response = $this->delete($this->endpoint.'test_table/'.$nonExistentId);
        $content = json_decode($response->getContent(), false);

        $this->assertEquals(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertEquals('Not Found', $content->status);
        $this->assertStringContainsString('Record not found', $content->data->message);
    }

    public function test_delete()
    {
        $this->markTestIncomplete('This test has not been implemented yet.');
    }
}
