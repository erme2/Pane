<?php

namespace Tests\Feature\crud;

use App\Mappers\AbstractMapper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Tests\TestCase;

class UniqueRulesOnUpdateTest extends TestCase
{
    use DatabaseTransactions;

    private string $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticateAsAdministrator();
        $this->withCsrfToken();
        $this->endpoint = '/crud/'.AbstractMapper::TABLES['test_table'];
    }

    private function recordWithName(string $name): array
    {
        $record = self::VALID_TEST_TABLE_RECORD;
        $record['name'] = $name;
        $record['email'] = 'unique'.Str::lower(Str::random(12)).'@example.test';

        return $record;
    }

    private function createRecord(string $name): int
    {
        $response = $this->post($this->endpoint, $this->recordWithName($name));
        $response->assertStatus(Response::HTTP_CREATED);

        return (int) json_decode($response->getContent(), false)->data[0]->table_id;
    }

    public function test_update_keeping_the_rows_own_unique_name_succeeds(): void
    {
        $name = 'own name '.Str::lower(Str::random(10));
        $id = $this->createRecord($name);

        $response = $this->put($this->endpoint.'/'.$id, $this->recordWithName($name));

        $response->assertStatus(Response::HTTP_OK);
    }

    public function test_update_taking_another_rows_unique_name_is_refused(): void
    {
        $taken = 'taken name '.Str::lower(Str::random(10));
        $this->createRecord($taken);
        $id = $this->createRecord('other name '.Str::lower(Str::random(10)));

        $response = $this->put($this->endpoint.'/'.$id, $this->recordWithName($taken));

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
    }

    public function test_create_with_a_duplicate_unique_name_is_refused(): void
    {
        $name = 'dup name '.Str::lower(Str::random(10));
        $this->createRecord($name);

        $response = $this->post($this->endpoint, $this->recordWithName($name));

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
    }
}
