<?php

namespace Tests\Feature\crud;

use App\Mappers\AbstractMapper;
use Illuminate\Http\Response;
use Tests\TestCase;

class CrudAuthorizationTest extends TestCase
{
    public function test_crud_routes_require_authentication(): void
    {
        $this->getJson('/crud/'.AbstractMapper::TABLES['test_table'])
            ->assertStatus(Response::HTTP_UNAUTHORIZED);

        $this->withCsrfToken()
            ->postJson('/crud/'.AbstractMapper::TABLES['test_table'], self::VALID_TEST_TABLE_RECORD)
            ->assertStatus(Response::HTTP_UNAUTHORIZED);

        $this->withCsrfToken()
            ->putJson('/crud/'.AbstractMapper::TABLES['test_table'].'/1', self::VALID_TEST_TABLE_RECORD)
            ->assertStatus(Response::HTTP_UNAUTHORIZED);

        $this->withCsrfToken()
            ->deleteJson('/crud/'.AbstractMapper::TABLES['test_table'].'/1')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_authenticated_user_can_read_non_system_subject(): void
    {
        $this->authenticateAsUser();

        $this->getJson('/crud/'.AbstractMapper::TABLES['test_table'].'/1')
            ->assertStatus(Response::HTTP_OK);
    }

    public function test_authenticated_user_cannot_mutate_non_system_subject(): void
    {
        $this->authenticateAsUser();

        $this->withCsrfToken()
            ->postJson('/crud/'.AbstractMapper::TABLES['test_table'], self::VALID_TEST_TABLE_RECORD)
            ->assertStatus(Response::HTTP_FORBIDDEN);

        $this->withCsrfToken()
            ->putJson('/crud/'.AbstractMapper::TABLES['test_table'].'/1', self::VALID_TEST_TABLE_RECORD)
            ->assertStatus(Response::HTTP_FORBIDDEN);

        $this->withCsrfToken()
            ->deleteJson('/crud/'.AbstractMapper::TABLES['test_table'].'/1')
            ->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_authenticated_user_cannot_read_system_subjects(): void
    {
        $this->authenticateAsUser();

        $this->getJson('/crud/'.AbstractMapper::TABLES['tables'])
            ->assertStatus(Response::HTTP_FORBIDDEN);

        $this->getJson('/crud/'.AbstractMapper::TABLES['users'])
            ->assertStatus(Response::HTTP_FORBIDDEN);
    }
}
