<?php

namespace Tests\Feature;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TermsAcceptanceTest extends TestCase
{
    public function test_user_without_acceptance_has_no_terms_version(): void
    {
        $this->actingAs($this->makePaneUser(User::STANDARD_USER_TYPE_ID));

        $this->asLatte()
            ->getJson('/api/v1/session')
            ->assertOk()
            ->assertJsonPath('data.user.attributes.terms_version', null)
            ->assertJsonPath('data.user.attributes.terms_accepted_at', null);
    }

    public function test_accepting_stores_the_version_and_session_shows_it(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($user);

        $this->asLatte()
            ->putJson('/api/v1/session/terms', ['terms_version' => '2026-11-20'])
            ->assertOk()
            ->assertJsonPath('data.terms_version', '2026-11-20')
            ->assertJsonStructure(['data' => ['terms_accepted_at'], 'meta' => ['request_id']]);

        $stored = $user->fresh();
        $this->assertSame('2026-11-20', $stored->terms_version);
        $this->assertNotNull($stored->terms_accepted_at);

        $this->asLatte()
            ->getJson('/api/v1/session')
            ->assertOk()
            ->assertJsonPath('data.user.attributes.terms_version', '2026-11-20')
            ->assertJsonPath('data.user.attributes.terms_accepted_at', $stored->terms_accepted_at->toJSON());
    }

    public function test_accepting_an_older_id_after_a_newer_one_replaces_it(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($user);

        $this->asLatte()->putJson('/api/v1/session/terms', ['terms_version' => 'v2'])->assertOk();
        $this->asLatte()->putJson('/api/v1/session/terms', ['terms_version' => 'v1'])->assertOk();

        $this->assertSame('v1', $user->fresh()->terms_version);
    }

    public function test_unauthenticated_request_is_refused(): void
    {
        $this->withCsrfToken()
            ->withHeader('Origin', 'https://latte.localhost')
            ->putJson('/api/v1/session/terms', ['terms_version' => 'v1'])
            ->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_refused_and_nothing_is_stored(array $payload): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $this->withCsrfToken()->actingAs($user);

        $this->asLatte()
            ->putJson('/api/v1/session/terms', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertNull($user->fresh()->terms_version);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['terms_version' => '  ']],
            'not a string' => [['terms_version' => 12]],
            'too long' => [['terms_version' => str_repeat('a', 101)]],
            'unknown field' => [['terms_version' => 'v1', 'terms_accepted_at' => '2020-01-01']],
        ];
    }

    private function asLatte(): static
    {
        return $this->withHeader('Origin', 'https://latte.localhost')->withV1ApplicationSession();
    }
}
