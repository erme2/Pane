<?php

namespace Tests\Feature\ManagedDatabase;

use App\Services\ManagedDatabaseConnector;
use App\Support\ManagedCredentials;
use App\Support\ManagedDatabase\ConnectionRejectedException;
use App\Support\ManagedDatabase\EgressDecision;
use App\Support\ManagedDatabase\EgressDeniedException;
use App\Support\ManagedDatabase\HostResolver;
use App\Support\ManagedDatabase\PdoFactory;
use InvalidArgumentException;
use PDO;
use Pdo\Mysql;
use RuntimeException;
use Tests\Support\FakeManagedDatabase;
use Tests\TestCase;

class ManagedDatabaseConnectorTest extends TestCase
{
    private FakeManagedDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.managed_databases.egress_allow', null);
        $this->db = new FakeManagedDatabase($this);
        $this->app->instance(HostResolver::class, $this->db->resolver());
        $this->app->instance(PdoFactory::class, $this->db->pdoFactory());
    }

    private function credentials(string $host = 'db.example.com', array $extra = []): ManagedCredentials
    {
        return new ManagedCredentials(['host' => $host, 'port' => 3307, 'database' => 'shop', 'username' => 'app', 'password' => 'secret', ...$extra]);
    }

    private function connector(): ManagedDatabaseConnector
    {
        return app(ManagedDatabaseConnector::class);
    }

    public function test_connects_to_the_pinned_address_over_tls_with_timeouts(): void
    {
        $this->connector()->connect('mysql', $this->credentials());

        $this->assertCount(1, $this->db->attempts);
        $attempt = $this->db->attempts[0];
        $this->assertSame('mysql:host=93.184.216.34;port=3307;dbname=shop;charset=utf8mb4', $attempt['dsn']);
        $this->assertSame(5, $attempt['options'][PDO::ATTR_TIMEOUT]);
        $this->assertFalse($attempt['options'][Mysql::ATTR_SSL_VERIFY_SERVER_CERT]);
        $this->assertContains('SET SESSION max_execution_time = 15000', $this->db->statements);
    }

    public function test_tries_the_next_checked_address_and_uses_ipv6_brackets(): void
    {
        $this->db->refuse = ['93.184.216.34'];

        $this->connector()->connect('mariadb', $this->credentials());

        $this->assertCount(2, $this->db->attempts);
        $this->assertSame('mysql:host=[2606:2800:220:1::1];port=3307;dbname=shop;charset=utf8mb4', $this->db->attempts[1]['dsn']);
        $this->assertContains('SET SESSION max_statement_time = 15', $this->db->statements);
    }

    public function test_client_certificates_go_to_temporary_files_that_are_removed(): void
    {
        $this->connector()->connect('mysql', $this->credentials(extra: ['client_certificate' => 'CERT', 'client_private_key' => 'KEY']));

        $options = $this->db->attempts[0]['options'];
        $this->assertFileDoesNotExist($options[Mysql::ATTR_SSL_CERT]);
        $this->assertFileDoesNotExist($options[Mysql::ATTR_SSL_KEY]);
    }

    public function test_a_server_without_tls_is_rejected_when_tls_is_required(): void
    {
        $this->db->tls = false;

        $this->expectException(ConnectionRejectedException::class);
        $this->connector()->connect('mysql', $this->credentials());
    }

    public function test_unencrypted_connections_only_reach_allowed_private_hosts(): void
    {
        try {
            $this->connector()->connect('mysql', $this->credentials(), tlsMode: 'disabled');
            $this->fail('Unencrypted public connection should be rejected.');
        } catch (ConnectionRejectedException) {
            $this->assertSame([], $this->db->attempts);
        }

        config()->set('services.managed_databases.egress_allow', 'localhost');
        $this->connector()->connect('mysql', $this->credentials('localhost'), tlsMode: 'disabled');

        $attempt = $this->db->lastAttempt();
        $this->assertNotNull($attempt);
        $this->assertSame('mysql:host=127.0.0.1;port=3307;dbname=shop;charset=utf8mb4', $attempt['dsn']);
        $this->assertArrayNotHasKey(Mysql::ATTR_SSL_VERIFY_SERVER_CERT, $attempt['options']);
    }

    public function test_verify_modes_are_not_supported_yet(): void
    {
        foreach (['verify_ca', 'verify_identity'] as $mode) {
            try {
                $this->connector()->connect('mysql', $this->credentials(), tlsMode: $mode);
                $this->fail("$mode should be rejected");
            } catch (ConnectionRejectedException) {
                $this->assertSame([], $this->db->attempts);
            }
        }
    }

    public function test_denied_destinations_never_reach_the_driver(): void
    {
        try {
            $this->connector()->connect('mysql', $this->credentials('internal.example.com'));
            $this->fail('Expected the connection to be denied.');
        } catch (EgressDeniedException $exception) {
            $this->assertSame(EgressDecision::BLOCKED_ADDRESS, $exception->decision->reason);
        }

        $this->assertSame([], $this->db->attempts);
    }

    public function test_connection_test_uses_the_same_path(): void
    {
        $this->connector()->test('mysql', $this->credentials());

        $this->assertCount(1, $this->db->attempts);
        $this->assertSame('SELECT 1', end($this->db->statements));
    }

    public function test_driver_errors_do_not_leak_credentials(): void
    {
        $this->db->refuse = ['93.184.216.34', '2606:2800:220:1::1'];

        try {
            $this->connector()->connect('mysql', $this->credentials());
            $this->fail('Expected the connection to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString("'app'", $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $this->assertStringContainsString('2002', $exception->getMessage());
        }
    }

    public function test_unsupported_drivers_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->connector()->connect('pgsql', $this->credentials());
    }
}
