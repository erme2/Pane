<?php

namespace Tests\Feature\ManagedDatabase\Integration;

use App\Models\ApplicationRegistration;
use App\Models\CatalogObject;
use App\Models\CatalogRefresh;
use App\Models\ManagedConnection;
use App\Models\ManagedCredentialSecret;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\ManagedDatabaseConnector;
use App\Services\OrganizationTenancyService;
use App\Support\ManagedCredentials;
use App\Support\ManagedDatabase\PrivilegeInspector;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PDO;
use PDOException;
use Tests\Support\RealManagedDatabase;
use Tests\TestCase;

/**
 * Managed connections against a real MySQL or MariaDB server (CI runs both).
 * Skipped unless PANE_IT_DB_HOST is set; see docs/testing.md.
 */
class RealManagedDatabaseTest extends TestCase
{
    private const string EXACT = 'GRANT SELECT, INSERT, UPDATE, DELETE ON `{db}`.* TO {account}';

    private RealManagedDatabase $server;

    protected function setUp(): void
    {
        parent::setUp();

        if (! RealManagedDatabase::configured()) {
            $this->markTestSkipped('Set PANE_IT_DB_HOST (and PANE_IT_DB_ENGINE, _PORT, _ROOT_PASSWORD) to run against a real database.');
        }

        $this->server = new RealManagedDatabase;
        config()->set('services.managed_databases.egress_allow', $this->server->host);
        config()->set('services.managed_databases.connect_timeout', 5);
        config()->set('services.managed_databases.query_timeout', 1);
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->cleanup();
        }

        parent::tearDown();
    }

    private function connect(string $user, string $password, string $tlsMode = 'required', ?string $database = null): PDO
    {
        return app(ManagedDatabaseConnector::class)->connect($this->server->engine, new ManagedCredentials([
            'host' => $this->server->host,
            'port' => $this->server->port,
            'database' => $database ?? $this->server->database,
            'username' => $user,
            'password' => $password,
        ]), tlsMode: $tlsMode);
    }

    private function cipher(PDO $pdo): string
    {
        $statement = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        $this->assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) ? (string) ($row[1] ?? '') : '';
    }

    /**
     * @param  list<string>  $grants
     * @return array{valid: bool, missing: list<string>, excessive: list<string>}
     */
    private function privileges(array $grants, ?callable $after = null): array
    {
        [$user, $password] = $this->server->account($grants);
        if ($after !== null) {
            $after($user);
        }

        return app(PrivilegeInspector::class)->inspect($this->connect($user, $password), $this->server->database);
    }

    public function test_required_tls_is_encrypted_and_disabled_works_for_an_allowed_private_host(): void
    {
        [$user, $password] = $this->server->account([self::EXACT]);

        $this->assertNotSame('', $this->cipher($this->connect($user, $password)));
        $this->assertSame('', $this->cipher($this->connect($user, $password, 'disabled')));
    }

    public function test_the_exact_privileges_pass_and_can_read_and_write(): void
    {
        $this->assertSame(['valid' => true, 'missing' => [], 'excessive' => []], $this->privileges([self::EXACT]));

        [$user, $password] = $this->server->account([self::EXACT]);
        $pdo = $this->connect($user, $password);
        $pdo->exec('INSERT INTO items VALUES (2, 5)');
        $pdo->exec('UPDATE items SET v = 6 WHERE id = 2');
        $statement = $pdo->query('SELECT v FROM items WHERE id = 2');
        $this->assertNotFalse($statement);
        $this->assertSame(6, (int) $statement->fetchColumn());
        $pdo->exec('DELETE FROM items WHERE id = 2');
    }

    public function test_missing_excessive_and_hidden_privileges_are_reported(): void
    {
        $missing = $this->privileges(['GRANT SELECT ON `{db}`.* TO {account}']);
        $this->assertFalse($missing['valid']);
        $this->assertSame(['INSERT', 'UPDATE', 'DELETE'], $missing['missing']);

        $ddl = $this->privileges([self::EXACT, 'GRANT CREATE, DROP ON `{db}`.* TO {account}']);
        $this->assertFalse($ddl['valid']);
        $this->assertContains("CREATE on `{$this->server->database}`", $ddl['excessive']);

        $global = $this->privileges([self::EXACT, 'GRANT PROCESS ON *.* TO {account}']);
        $this->assertContains('PROCESS on *.*', $global['excessive']);

        $grantOption = $this->privileges(['GRANT SELECT, INSERT, UPDATE, DELETE ON `{db}`.* TO {account} WITH GRANT OPTION']);
        $this->assertContains("GRANT OPTION on `{$this->server->database}`", $grantOption['excessive']);

        $other = $this->server->createDatabase($this->server->database.'_other');
        $otherDb = $this->privileges([self::EXACT, "GRANT SELECT ON `$other`.* TO {account}"]);
        // `_` in the name is a wildcard, so the label may say so.
        $this->assertNotSame([], array_filter($otherDb['excessive'], fn (string $item): bool => str_starts_with($item, "SELECT on `$other`")), json_encode($otherDb) ?: '');

        $role = $this->privileges([self::EXACT], fn (string $user) => $this->server->grantRole($user, 'GRANT SELECT ON `{db}`.* TO {role}'));
        $this->assertContains('role or unrecognised grant', $role['excessive']);
    }

    public function test_underscore_wildcard_grants_count_only_when_they_match_no_other_database(): void
    {
        // Hosting panels grant `user_db`.* with an unescaped `_` wildcard.
        $own = $this->privileges(['GRANT SELECT, INSERT, UPDATE, DELETE ON `'.str_replace('it', 'i_', $this->server->database).'`.* TO {account}']);
        $this->assertTrue($own['valid'], json_encode($own) ?: '');

        $lookalike = $this->server->createDatabase('ix'.substr($this->server->database, 2));
        $shared = $this->privileges(['GRANT SELECT, INSERT, UPDATE, DELETE ON `'.str_replace('it', 'i_', $this->server->database).'`.* TO {account}']);
        $this->assertFalse($shared['valid']);
        $this->assertNotSame('', $lookalike);
    }

    public function test_slow_selects_are_stopped_by_the_query_timeout(): void
    {
        [$user, $password] = $this->server->account([self::EXACT]);
        $pdo = $this->connect($user, $password);

        $this->assertWithinSeconds(2.5, function () use ($pdo): void {
            $pdo->query('SELECT SLEEP(5) FROM items');
        });
    }

    public function test_lock_waits_are_bounded_by_the_query_timeout(): void
    {
        [$user, $password] = $this->server->account([self::EXACT]);
        $pdo = $this->connect($user, $password);
        $root = $this->server->root();
        $root->exec("USE `{$this->server->database}`");
        $root->beginTransaction();
        $root->query('SELECT * FROM items WHERE id = 1 FOR UPDATE');

        try {
            $pdo->exec('UPDATE items SET v = v + 1 WHERE id = 1');
            $this->fail('The update should wait for the lock and time out.');
        } catch (PDOException $exception) {
            // 1205: lock wait timeout. MariaDB's statement timeout (1969) can fire first at the same limit.
            $this->assertContains((int) ($exception->errorInfo[1] ?? 0), [1205, 1969], $exception->getMessage());
        } finally {
            $root->rollBack();
        }
    }

    public function test_a_slow_write_does_not_hold_the_worker_past_the_timeout(): void
    {
        [$user, $password] = $this->server->account([self::EXACT]);
        $pdo = $this->connect($user, $password);

        // MySQL's max_execution_time doesn't cover writes: MariaDB stops the
        // statement, MySQL is cut off by the client read timeout (query + 2 s).
        $this->assertWithinSeconds(4.5, function () use ($pdo): void {
            $pdo->exec('UPDATE items SET v = SLEEP(6) WHERE id = 1');
        });
    }

    public function test_connection_lifecycle_and_isolation_through_the_api(): void
    {
        [$application, $organization, $admin] = $this->apiFixture();
        [$user, $password] = $this->server->account([self::EXACT]);
        $api = fn (string $method, string $path = '', array $body = [], ?string $ifMatch = null, ?Organization $org = null): TestResponse => $this->connectionsApi($application, $admin, $org ?? $organization, $method, $path, $body, $ifMatch);
        $profile = [
            'engine' => $this->server->engine, 'name' => 'Shop', 'host' => $this->server->host, 'port' => $this->server->port,
            'database' => $this->server->database, 'username' => $user, 'tls_mode' => 'required',
        ];

        $created = $api('POST', '', [...$profile, 'credentials' => ['password' => $password]])->assertCreated();
        $id = (string) $created->json('data.id');
        $api('POST', "/$id/test")->assertOk()->assertJsonPath('data.attributes.reachable', true)->assertJsonPath('data.attributes.privileges_valid', true);

        // A wrong password is rejected and nothing changes.
        $etag = fn (): string => (string) $api('GET', "/$id")->assertOk()->headers->get('ETag');
        $api('PATCH', "/$id", ['credentials' => ['password' => 'wrong']], $etag())
            ->assertUnprocessable()->assertJsonPath('error.code', 'connection_policy_rejected');

        // Rotate the password on the server, then in Pane.
        $this->server->root()->exec("ALTER USER '$user'@'%' IDENTIFIED BY 'rotated-$password'");
        $api('POST', "/$id/test")->assertUnprocessable()->assertJsonPath('error.code', 'connection_test_failed');
        $this->assertFalse(ManagedConnection::query()->findOrFail($id)->last_test_reachable);
        $api('PATCH', "/$id", ['credentials' => ['password' => "rotated-$password"]], $etag())->assertOk();
        $api('POST', "/$id/test")->assertOk()->assertJsonPath('data.attributes.reachable', true);

        // Extra privileges show up on the next test.
        $this->server->root()->exec("GRANT CREATE ON `{$this->server->database}`.* TO '$user'@'%'");
        $api('POST', "/$id/test")->assertUnprocessable()->assertJsonPath('error.message', fn (string $message): bool => str_contains($message, 'CREATE'));
        $tested = ManagedConnection::query()->findOrFail($id);
        $this->assertTrue($tested->last_test_reachable);
        $this->assertFalse($tested->last_test_privileges_valid);
        $this->server->root()->exec("REVOKE CREATE ON `{$this->server->database}`.* FROM '$user'@'%'");

        // Quota: the organization may have 2 active connections.
        $quota = fn (): int => (int) $organization->fresh()?->active_database_connections;
        $this->assertSame(1, $quota());
        $api('POST', '', [...$profile, 'name' => 'Second', 'credentials' => ['password' => "rotated-$password"]])->assertCreated();
        $this->assertSame(2, $quota());
        $api('POST', '', [...$profile, 'name' => 'Third', 'credentials' => ['password' => "rotated-$password"]])
            ->assertConflict()->assertJsonPath('error.code', 'quota_exceeded');
        $this->assertSame(2, $quota());

        // Disabling frees a slot; reactivating takes it back, and fails when the slot is gone.
        $api('DELETE', "/$id", ifMatch: $etag())->assertNoContent();
        $this->assertSame(ManagedConnection::STATUS_DISABLED, ManagedConnection::query()->findOrFail($id)->status);
        $this->assertSame(1, $quota());
        $third = $api('POST', '', [...$profile, 'name' => 'Third', 'credentials' => ['password' => "rotated-$password"]])->assertCreated();
        $this->assertSame(2, $quota());
        $api('PATCH', "/$id", ['status' => 'active'], $etag())->assertConflict()->assertJsonPath('error.code', 'quota_exceeded');
        $this->assertSame(ManagedConnection::STATUS_DISABLED, ManagedConnection::query()->findOrFail($id)->status);

        $thirdId = (string) $third->json('data.id');
        $api('DELETE', "/$thirdId", ifMatch: (string) $api('GET', "/$thirdId")->headers->get('ETag'))->assertNoContent();
        $api('PATCH', "/$id", ['status' => 'active'], $etag())->assertOk()->assertJsonPath('data.attributes.status', 'active');
        $this->assertSame(2, $quota());

        // Another organization can't see it.
        $other = app(OrganizationTenancyService::class)->createOrganization('Other', 'other-'.Str::uuid());
        $api('GET', "/$id", org: $other)->assertForbidden();
    }

    public function test_catalog_discovery_and_refresh_against_the_real_server(): void
    {
        [$application, $organization, $admin] = $this->apiFixture();
        [$user, $password] = $this->server->account([self::EXACT]);
        $db = $this->server->database;
        $root = $this->server->root();
        $root->exec("CREATE TABLE `$db`.customers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, pane_membership_id CHAR(36) NOT NULL, email VARCHAR(320) NOT NULL,"
            .' created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL, INDEX customers_membership (pane_membership_id)) ENGINE=InnoDB');
        $root->exec("CREATE TABLE `$db`.orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, pane_membership_id CHAR(36) NOT NULL, customer_id BIGINT UNSIGNED NOT NULL,"
            .' total DECIMAL(10,2) NOT NULL, note TEXT NULL, shape GEOMETRY NULL, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,'
            .' INDEX orders_membership (pane_membership_id), CONSTRAINT orders_customer_fk FOREIGN KEY (customer_id) REFERENCES customers (id)) ENGINE=InnoDB');
        $root->exec("CREATE VIEW `$db`.order_totals AS SELECT customer_id, SUM(total) AS total FROM `$db`.orders GROUP BY customer_id");

        $api = fn (string $method, string $path = '', array $body = [], ?string $ifMatch = null): TestResponse => $this->connectionsApi($application, $admin, $organization, $method, $path, $body, $ifMatch);
        $id = (string) $api('POST', '', [
            'engine' => $this->server->engine, 'name' => 'Shop', 'host' => $this->server->host, 'port' => $this->server->port,
            'database' => $db, 'username' => $user, 'tls_mode' => 'required', 'credentials' => ['password' => $password],
        ])->assertCreated()->json('data.id');
        $api('POST', "/$id/catalog-refreshes")->assertAccepted()->assertJsonPath('data.attributes.status', 'completed');

        $object = fn (string $kind, string $name): CatalogObject => CatalogObject::query()->where('connection_id', $id)->where('kind', $kind)->where('name', $name)->firstOrFail();
        $this->assertSame('available', $object('schema', $db)->status);
        $this->assertSame('available', $object('table', 'customers')->status, implode(', ', $object('table', 'customers')->issues));
        $this->assertSame('available', $object('table', 'orders')->status, implode(', ', $object('table', 'orders')->issues));
        $this->assertSame(['id'], $object('table', 'orders')->structure['primary_key']);
        $this->assertSame('decimal', $object('column', 'orders.total')->structure['type']);
        $this->assertTrue($object('column', 'orders.id')->structure['auto_increment']);
        $this->assertSame('incompatible', $object('column', 'orders.shape')->status);
        $this->assertSame(['table' => 'orders', 'columns' => ['customer_id'], 'referenced_table' => 'customers', 'referenced_columns' => ['id']], $object('relationship', 'orders.orders_customer_fk')->structure);
        $this->assertContains('not_a_base_table', $object('table', 'order_totals')->issues);
        $this->assertContains('membership_column_missing', $object('table', 'items')->issues);

        $orders = $object('table', 'orders');
        $api('PATCH', "/$id/catalog/{$orders->getKey()}", ['description' => 'Customer orders'], '"'.$orders->versionTag().'"')->assertOk();

        // Rename a column and drop a table on the server, then refresh.
        $root->exec("DROP VIEW `$db`.order_totals");
        $root->exec("ALTER TABLE `$db`.orders CHANGE note notes TEXT NULL");
        $api('POST', "/$id/catalog-refreshes")->assertAccepted();

        $this->assertSame('Customer orders', $object('table', 'orders')->description);
        $this->assertSame('missing', $object('column', 'orders.note')->status);
        $this->assertSame('available', $object('column', 'orders.notes')->status);
        $this->assertSame('missing', $object('table', 'order_totals')->status);

        // A connection to another database has its own catalog.
        $otherDb = $this->server->createDatabase('it_other_'.bin2hex(random_bytes(3)));
        $root->exec("CREATE TABLE `$otherDb`.things (id INT PRIMARY KEY) ENGINE=InnoDB");
        [$otherUser, $otherPassword] = $this->server->account(["GRANT SELECT, INSERT, UPDATE, DELETE ON `$otherDb`.* TO {account}"]);
        $other = (string) $api('POST', '', [
            'engine' => $this->server->engine, 'name' => 'Other', 'host' => $this->server->host, 'port' => $this->server->port,
            'database' => $otherDb, 'username' => $otherUser, 'tls_mode' => 'required', 'credentials' => ['password' => $otherPassword],
        ])->assertCreated()->json('data.id');
        $api('POST', "/$other/catalog-refreshes")->assertAccepted();
        $this->assertSame(['things'], CatalogObject::query()->where('connection_id', $other)->where('kind', 'table')->pluck('name')->all());
        $api('GET', "/$other/catalog/{$orders->getKey()}")->assertNotFound();
    }

    private function assertWithinSeconds(float $limit, callable $statement): void
    {
        $started = microtime(true);
        try {
            $statement();
        } catch (PDOException) {
            // Stopping with an error is fine; taking too long isn't.
        }
        $this->assertLessThan($limit, microtime(true) - $started);
    }

    /**
     * @return array{ApplicationRegistration, Organization, User}
     */
    private function apiFixture(): array
    {
        CatalogObject::query()->delete();
        CatalogRefresh::query()->delete();
        ManagedConnection::query()->delete();
        ManagedCredentialSecret::query()->delete();
        OrganizationInvitation::query()->delete();
        ApplicationRegistration::query()->delete();
        OrganizationMembership::query()->delete();
        Organization::query()->delete();
        User::query()->delete();

        config()->set('services.managed_credentials.active_key_id', 'key-v1');
        config()->set('services.managed_credentials.keys', json_encode([
            'key-v1' => 'base64:'.base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)),
        ], JSON_THROW_ON_ERROR));
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $application = app(ApplicationRegistryService::class)->configuredLatteApplication();
        $organization = $application->organization()->firstOrFail();
        $organization->forceFill(['database_limit' => 2, 'active_database_connections' => 0])->save();
        $admin = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        app(OrganizationTenancyService::class)->addOrReactivateMembership($organization, $admin, OrganizationMembership::ROLE_ADMINISTRATOR);

        return [$application, $organization, $admin];
    }

    private function connectionsApi(ApplicationRegistration $application, User $admin, Organization $organization, string $method, string $path, array $body, ?string $ifMatch): TestResponse
    {
        $this->flushHeaders();
        $this->withCsrfToken()->actingAs($admin);
        $request = $this->withV1ApplicationSession($application)->withHeader('Origin', 'https://latte.test');
        if ($ifMatch !== null) {
            $request = $request->withHeader('If-Match', $ifMatch);
        }

        return $request->json($method, "/api/v1/organizations/{$organization->organization_id}/connections$path", $body);
    }
}
