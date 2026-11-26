<?php

namespace Tests\Feature\crud;

use App\Mappers\AbstractMapper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NullableClearTest extends TestCase
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

    /** @var array<string, mixed> */
    private array $record = [];

    private function record(array $overrides = []): array
    {
        return array_merge(self::VALID_TEST_TABLE_RECORD, $this->record, $overrides);
    }

    private function createRecord(): int
    {
        $this->record = [
            'name' => 'nullable '.Str::lower(Str::random(10)),
            'email' => 'nullable'.Str::lower(Str::random(12)).'@example.test',
        ];
        $response = $this->post($this->endpoint, $this->record());
        $response->assertStatus(Response::HTTP_CREATED);

        return (int) json_decode($response->getContent(), false)->data[0]->table_id;
    }

    private function column(int $id, string $column): mixed
    {
        return DB::table(AbstractMapper::TABLES['test_table'])->where('table_id', $id)->value($column);
    }

    public function test_update_sending_null_clears_a_nullable_field(): void
    {
        $id = $this->createRecord();
        $this->assertSame('a basic description', $this->column($id, 'description'));

        $response = $this->put($this->endpoint.'/'.$id, $this->record(['description' => null]));

        $response->assertStatus(Response::HTTP_OK);
        $this->assertNull($this->column($id, 'description'));
    }

    public function test_update_sending_null_clears_a_nullable_boolean(): void
    {
        $id = $this->createRecord();
        $this->assertTrue((bool) $this->column($id, 'is_active'));

        $response = $this->put($this->endpoint.'/'.$id, $this->record(['is_active' => null]));

        $response->assertStatus(Response::HTTP_OK);
        $this->assertNull($this->column($id, 'is_active'));
    }

    public function test_update_omitting_a_nullable_field_leaves_it_unchanged(): void
    {
        $id = $this->createRecord();

        $response = $this->put($this->endpoint.'/'.$id, $this->record(['name' => 'renamed '.Str::lower(Str::random(10))]));

        $response->assertStatus(Response::HTTP_OK);
        $this->assertSame('a basic description', $this->column($id, 'description'));
    }

    public function test_update_sending_null_for_a_required_field_is_refused(): void
    {
        $id = $this->createRecord();

        $response = $this->put($this->endpoint.'/'.$id, $this->record(['name' => null]));

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertNotNull($this->column($id, 'name'));
    }

    public function test_create_with_null_for_a_nullable_field_is_still_refused(): void
    {
        $this->record = [
            'name' => 'create null '.Str::lower(Str::random(10)),
            'email' => 'createnull'.Str::lower(Str::random(12)).'@example.test',
        ];

        $response = $this->post($this->endpoint, $this->record(['description' => null]));

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
    }
}
