<?php

namespace Tests\Feature\ManagedDatabase;

use App\Services\ManagedDatabaseConnector;
use App\Support\ManagedCredentials;
use App\Support\ManagedDatabase\EgressDecision;
use App\Support\ManagedDatabase\EgressDeniedException;
use App\Support\ManagedDatabase\HostResolver;
use App\Support\ManagedDatabase\PdoFactory;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ManagedDatabaseConnectorTest extends TestCase
{
    /** @var list<array{dsn: string, username: ?string, options: array<int, mixed>}> */
    private array $attempts = [];

    /** @var list<string> */
    private array $statements = [];

    /** @var list<string> addresses that refuse the connection */
    private array $refuse = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.managed_databases.egress_allow', null);
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $hostname): array
            {
                return match ($hostname) {
                    'db.example.com' => ['93.184.216.34', '2606:2800:220:1::1'],
                    'internal.example.com' => ['10.0.0.8'],
                    default => [],
                };
            }
        });

        $test = $this;
        $this->app->instance(PdoFactory::class, new class($test) extends PdoFactory
        {
            public function __construct(private ManagedDatabaseConnectorTest $test) {}

            public function make(string $dsn, ?string $username, #[\SensitiveParameter] ?string $password, array $options): PDO
            {
                return $this->test->fakeConnect($dsn, $username, $options);
            }
        });
    }

    /**
     * @param  array<int, mixed>  $options
     */
    public function fakeConnect(string $dsn, ?string $username, array $options): PDO
    {
        $this->attempts[] = ['dsn' => $dsn, 'username' => $username, 'options' => $options];
        foreach ($this->refuse as $address) {
            if (str_contains($dsn, $address)) {
                throw new PDOException('Connection refused for user app', 2002);
            }
        }

        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql): int {
            $this->statements[] = $sql;

            return 0;
        });
        $pdo->method('query')->willReturnCallback(function (string $sql) {
            $this->statements[] = $sql;

            return false;
        });

        return $pdo;
    }

    private function credentials(string $host = 'db.example.com'): ManagedCredentials
    {
        return new ManagedCredentials(['host' => $host, 'port' => 3307, 'database' => 'shop', 'username' => 'app', 'password' => 'secret']);
    }

    private function connector(): ManagedDatabaseConnector
    {
        return app(ManagedDatabaseConnector::class);
    }

    public function test_connects_to_the_pinned_address_with_timeouts(): void
    {
        $this->connector()->connect('mysql', $this->credentials());

        $this->assertCount(1, $this->attempts);
        $this->assertSame('mysql:host=93.184.216.34;port=3307;dbname=shop;charset=utf8mb4', $this->attempts[0]['dsn']);
        $this->assertStringNotContainsString('db.example.com', $this->attempts[0]['dsn']);
        $this->assertSame(5, $this->attempts[0]['options'][PDO::ATTR_TIMEOUT]);
        $this->assertSame(['SET SESSION max_execution_time = 15000'], $this->statements);
    }

    public function test_tries_the_next_checked_address_and_uses_ipv6_brackets(): void
    {
        $this->refuse = ['93.184.216.34'];

        $this->connector()->connect('mariadb', $this->credentials());

        $this->assertCount(2, $this->attempts);
        $this->assertSame('mysql:host=[2606:2800:220:1::1];port=3307;dbname=shop;charset=utf8mb4', $this->attempts[1]['dsn']);
        $this->assertSame(['SET SESSION max_statement_time = 15'], $this->statements);
    }

    public function test_denied_destinations_never_reach_the_driver(): void
    {
        try {
            $this->connector()->connect('mysql', $this->credentials('internal.example.com'));
            $this->fail('Expected the connection to be denied.');
        } catch (EgressDeniedException $exception) {
            $this->assertSame(EgressDecision::BLOCKED_ADDRESS, $exception->decision->reason);
        }

        $this->assertSame([], $this->attempts);
    }

    public function test_connection_test_uses_the_same_path(): void
    {
        $this->connector()->test('mysql', $this->credentials());

        $this->assertCount(1, $this->attempts);
        $this->assertSame('SELECT 1', end($this->statements));
    }

    public function test_driver_errors_do_not_leak_credentials(): void
    {
        $this->refuse = ['93.184.216.34', '2606:2800:220:1::1'];

        try {
            $this->connector()->connect('mysql', $this->credentials());
            $this->fail('Expected the connection to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('app', $exception->getMessage());
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
