<?php

namespace Tests\Feature\ManagedDatabase;

use App\Models\ApplicationRegistration;
use App\Models\AuditEvent;
use App\Models\ManagedConnection;
use App\Models\ManagedCredentialSecret;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\ManagedCredentialVault;
use App\Services\OrganizationTenancyService;
use App\Support\ManagedDatabase\HostResolver;
use App\Support\ManagedDatabase\PdoFactory;
use App\Support\PaneTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeManagedDatabase;
use Tests\TestCase;

class ManagedConnectionApiTest extends TestCase
{
    private FakeManagedDatabase $db;

    private ApplicationRegistration $application;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        ManagedConnection::query()->delete();
        ManagedCredentialSecret::query()->delete();
        OrganizationInvitation::query()->delete();
        ApplicationRegistration::query()->delete();
        OrganizationMembership::query()->delete();
        Organization::query()->delete();
        User::query()->delete();

        config()->set('services.managed_databases.egress_allow', null);
        config()->set('services.managed_credentials.active_key_id', 'key-v1');
        config()->set('services.managed_credentials.keys', json_encode([
            'key-v1' => 'base64:'.base64_encode(str_repeat('k', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)),
        ], JSON_THROW_ON_ERROR));
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $this->db = new FakeManagedDatabase($this);
        $this->app->instance(HostResolver::class, $this->db->resolver());
        $this->app->instance(PdoFactory::class, $this->db->pdoFactory());

        $this->application = app(ApplicationRegistryService::class)->configuredLatteApplication();
        $this->organization = $this->application->organization()->firstOrFail();
        $this->organization->forceFill(['database_limit' => 2, 'active_database_connections' => 0])->save();
        $this->admin = $this->member(OrganizationMembership::ROLE_ADMINISTRATOR);
    }

    private function member(string $role): User
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        app(OrganizationTenancyService::class)->addOrReactivateMembership($this->organization, $user, $role);

        return $user;
    }

    private function api(string $method, string $path = '', array $body = [], ?string $ifMatch = null, ?User $as = null): TestResponse
    {
        $this->flushHeaders();
        $this->withCsrfToken()->actingAs($as ?? $this->admin);
        $request = $this->withV1ApplicationSession($this->application)->withHeader('Origin', 'https://latte.test');
        if ($ifMatch !== null) {
            $request = $request->withHeader('If-Match', $ifMatch);
        }

        return $request->json($method, "/api/v1/organizations/{$this->organization->organization_id}/connections$path", $body);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'engine' => 'mysql', 'name' => 'Shop', 'host' => 'db.example.com', 'port' => 3306,
            'database' => 'shop', 'username' => 'app', 'tls_mode' => 'required',
            'credentials' => ['password' => 'very-secret-password'],
            ...$overrides,
        ];
    }

    private function create(array $overrides = []): TestResponse
    {
        return $this->api('POST', '', $this->payload($overrides));
    }

    private function quota(): int
    {
        return (int) $this->organization->fresh()->active_database_connections;
    }

    public function test_admin_creates_a_tested_connection_and_secrets_are_write_only(): void
    {
        $response = $this->create()->assertCreated()->assertHeader('ETag');

        $response->assertJsonPath('data.type', 'connection')
            ->assertJsonPath('data.attributes.host', 'db.example.com')
            ->assertJsonPath('data.attributes.database', 'shop')
            ->assertJsonPath('data.attributes.credentials_configured', true)
            ->assertJsonMissingPath('data.attributes.credentials')
            ->assertJsonMissingPath('data.attributes.password');
        $this->assertStringNotContainsString('very-secret-password', $response->getContent());

        $connection = ManagedConnection::query()->findOrFail($response->json('data.id'));
        $this->assertSame(1, $this->quota());
        $this->assertSame('very-secret-password', app(ManagedCredentialVault::class)->reveal($connection->secret)->get('password'));
        $raw = (string) DB::table(PaneTable::name(PaneTable::MANAGED_CREDENTIAL_SECRETS))->value('envelope');
        $this->assertStringNotContainsString('very-secret-password', $raw);
        $this->assertSame('very-secret-password', $this->db->attempts[0]['password']);
        $this->assertStringNotContainsString('very-secret-password', AuditEvent::query()->get()->toJson());
    }

    public function test_failed_tests_save_nothing(): void
    {
        $this->db->grants = ['GRANT ALL PRIVILEGES ON `shop`.* TO `app`@`%`'];
        $this->create()->assertUnprocessable()->assertJsonPath('error.code', 'connection_policy_rejected')
            ->assertJsonPath('error.message', fn (string $message): bool => str_contains($message, 'ALL PRIVILEGES'));

        $this->db->grants = ['GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.* TO `app`@`%`'];
        $this->create(['host' => 'internal.example.com'])->assertUnprocessable()->assertJsonPath('error.code', 'connection_policy_rejected');
        $this->create(['tls_mode' => 'verify_identity'])->assertUnprocessable();
        $this->create(['tls_mode' => 'disabled'])->assertUnprocessable();

        $this->assertSame(0, ManagedConnection::query()->count());
        $this->assertSame(0, ManagedCredentialSecret::query()->count());
        $this->assertSame(0, $this->quota());
    }

    public function test_unencrypted_localhost_works_with_an_allow_rule(): void
    {
        config()->set('services.managed_databases.egress_allow', 'localhost');

        $this->create(['host' => 'localhost', 'tls_mode' => 'disabled'])->assertCreated();
    }

    public function test_validation_names_and_quota(): void
    {
        $this->create(['extra' => 1])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->create(['credentials' => ['password' => 'x', 'token' => 'y']])->assertUnprocessable();
        $this->create(['port' => 70000])->assertUnprocessable();

        $this->create()->assertCreated();
        $this->create()->assertConflict()->assertJsonPath('error.code', 'duplicate_resource');
        $this->create(['name' => 'Second'])->assertCreated();
        $this->create(['name' => 'Third'])->assertConflict()->assertJsonPath('error.code', 'quota_exceeded');
        $this->create(['name' => 'Spare', 'status' => 'disabled'])->assertCreated();
        $this->assertSame(2, $this->quota());
    }

    public function test_only_organization_administrators_can_manage_connections(): void
    {
        $user = $this->member(OrganizationMembership::ROLE_USER);

        $this->api('GET', as: $user)->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $this->api('POST', '', $this->payload(), as: $user)->assertForbidden();
    }

    public function test_list_and_show(): void
    {
        $id = $this->create()->json('data.id');
        $this->create(['name' => 'Second']);

        $this->api('GET', '?page[limit]=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.page.has_more', true);
        $this->api('GET', "/$id")->assertOk()->assertJsonPath('data.id', $id);
        $this->api('GET', '/'.Str::uuid())->assertNotFound();
        $this->api('GET', '/not-a-uuid')->assertBadRequest();
    }

    public function test_updates_retest_only_when_connectivity_changes(): void
    {
        $created = $this->create();
        $id = $created->json('data.id');
        $attempts = count($this->db->attempts);

        $renamed = $this->api('PATCH', "/$id", ['name' => 'Renamed'], $created->headers->get('ETag'))->assertOk();
        $this->assertCount($attempts, $this->db->attempts);

        $this->api('PATCH', "/$id", ['name' => 'Again'], $created->headers->get('ETag'))->assertStatus(412);
        $this->api('PATCH', "/$id", ['name' => 'Again'])->assertStatus(428);

        $this->db->grants = ['GRANT SELECT ON `shop`.* TO `app`@`%`'];
        $this->api('PATCH', "/$id", ['username' => 'other'], $renamed->headers->get('ETag'))->assertUnprocessable();
        $this->assertSame('app', ManagedConnection::query()->findOrFail($id)->username);
    }

    public function test_new_credentials_replace_the_secret(): void
    {
        $created = $this->create();
        $connection = ManagedConnection::query()->findOrFail($created->json('data.id'));
        $oldSecret = $connection->managed_credential_secret_id;

        $this->api('PATCH', "/{$connection->connection_id}", ['credentials' => ['password' => 'rotated']], $created->headers->get('ETag'))->assertOk();

        $connection->refresh();
        $this->assertNotSame($oldSecret, $connection->managed_credential_secret_id);
        $this->assertSame(ManagedCredentialSecret::STATUS_REVOKED, ManagedCredentialSecret::query()->findOrFail($oldSecret)->status);
        $this->assertSame('rotated', end($this->db->attempts)['password']);
    }

    public function test_delete_disables_and_reactivation_retests(): void
    {
        $created = $this->create();
        $id = $created->json('data.id');

        $this->api('DELETE', "/$id")->assertStatus(428);
        $this->api('DELETE', "/$id", ifMatch: $created->headers->get('ETag'))->assertNoContent();
        $this->assertSame(ManagedConnection::STATUS_DISABLED, ManagedConnection::query()->findOrFail($id)->status);
        $this->assertSame(0, $this->quota());

        $etag = $this->api('GET', "/$id")->headers->get('ETag');
        $attempts = count($this->db->attempts);
        $this->api('PATCH', "/$id", ['status' => 'active'], $etag)->assertOk()->assertJsonPath('data.attributes.status', 'active');
        $this->assertGreaterThan($attempts, count($this->db->attempts));
        $this->assertSame(1, $this->quota());
    }

    public function test_connection_test_endpoint_reports_and_is_rate_limited(): void
    {
        $id = $this->create()->json('data.id');
        RateLimiter::clear("managed-connection-test:$id");

        $this->api('POST', "/$id/test")->assertOk()
            ->assertJsonPath('data.type', 'connection_test')
            ->assertJsonPath('data.attributes.reachable', true)
            ->assertJsonPath('data.attributes.privileges_valid', true);

        $this->db->grants = ['GRANT SELECT, INSERT, UPDATE, DELETE, DROP ON `shop`.* TO `app`@`%`'];
        $this->api('POST', "/$id/test")->assertUnprocessable()->assertJsonPath('error.code', 'connection_test_failed');
        $connection = ManagedConnection::query()->findOrFail($id);
        $this->assertTrue($connection->last_test_reachable);
        $this->assertFalse($connection->last_test_privileges_valid);

        for ($i = 0; $i < 3; $i++) {
            $this->api('POST', "/$id/test");
        }
        $this->api('POST', "/$id/test")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_connections_of_other_organizations_are_invisible(): void
    {
        $id = $this->create()->json('data.id');
        $other = app(OrganizationTenancyService::class)->createOrganization('Other', 'other '.Str::uuid());
        ManagedConnection::query()->whereKey($id)->update(['organization_id' => $other->getKey()]);

        $this->api('GET', "/$id")->assertNotFound();
    }
}
