<?php

namespace Tests;

use App\Models\ApplicationRegistration;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    const TEST_TABLE_NAME = 'test_table';

    const TEST_TABLE_PRIMARY_KEY = 'table_id';

    const array VALID_TEST_TABLE_RECORD = [
        'name' => 'just a test name',
        'description' => 'a basic description',
        'is_active' => true,
        'test_date' => '07-03-2017',
        'test_array' => ['this', 'is', 'an', 'array'],
        'password' => 'Pa$$word123#',
        'email' => 'test@gmail.com',
        'test_json' => '{"some": "JSON"}',
        'numero' => 33,
    ];

    const array INVALID_TEST_TABLE_RECORD = [
        'name' => '',
        'description' => [1 => 2],
        'is_active' => ['aa'],
        'test_date' => 'not a date',
        'test_array' => 'not an array',
        'password' => '123',
        'email' => 'invalid-email',
        'test_json' => 'not json',
        'numero' => 3,
    ];

    const array UPDATED_VALID_TEST_TABLE_RECORD = [
        'table_id' => 2, // this is the primary key, it should be present in the record to update
        'name' => 'this name was updated',
        'description' => 'this description was updated',
        'is_active' => false,
        'test_date' => '22-04-2021',
        'test_array' => ['this', 'is', 'another', 'array'],
        'password' => 'Hacked?123#',
        'email' => 'another@gmail.com',
        'test_json' => '{"some": "JSON", "more": "data"}',
        'numero' => 55,
    ];

    protected function authenticateAsAdministrator(): User
    {
        $user = $this->makePaneUser(1);
        $this->actingAs($user);

        return $user;
    }

    protected function withCsrfToken(): static
    {
        $token = (string) Str::uuid();

        return $this
            ->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token);
    }

    /**
     * @return array<string, string>
     */
    protected function v1ApplicationSession(?ApplicationRegistration $application = null): array
    {
        $application ??= app(ApplicationRegistryService::class)->configuredLatteApplication();

        return [
            'pane_v1_application_id' => (string) $application->getKey(),
            'pane_v1_application_session_version' => $application->session_version,
        ];
    }

    protected function withV1ApplicationSession(?ApplicationRegistration $application = null): static
    {
        return $this->withSession($this->v1ApplicationSession($application));
    }

    protected function authenticateAsUser(): User
    {
        $user = $this->makePaneUser(2);
        $this->actingAs($user);

        return $user;
    }

    protected function makePaneUser(int $userTypeId): User
    {
        $suffix = (string) Str::uuid();

        return User::query()->create([
            'user_type_id' => $userTypeId,
            'name' => "test-user-$suffix",
            'email' => "test-user-$suffix@example.com",
            'password' => 'password',
            'is_active' => true,
        ]);
    }
}
