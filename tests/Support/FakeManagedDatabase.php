<?php

namespace Tests\Support;

use App\Support\ManagedDatabase\HostResolver;
use App\Support\ManagedDatabase\PdoFactory;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * A pretend MySQL server for managed-connection tests: fixed DNS answers,
 * recorded connection attempts, configurable grants and TLS support.
 */
final class FakeManagedDatabase
{
    /** @var array<string, list<string>> */
    public array $dns = [
        'db.example.com' => ['93.184.216.34', '2606:2800:220:1::1'],
        'internal.example.com' => ['10.0.0.8'],
        'localhost' => ['127.0.0.1'],
    ];

    /** @var list<string> */
    public array $grants = [
        'GRANT USAGE ON *.* TO `app`@`%`',
        'GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.* TO `app`@`%`',
    ];

    /** @var list<string> */
    public array $databases = ['information_schema', 'shop'];

    public bool $tls = true;

    /** @var list<string> addresses that refuse connections */
    public array $refuse = [];

    /** @var list<array{dsn: string, username: ?string, password: ?string, options: array<int, mixed>, read_timeout: string|false}> */
    public array $attempts = [];

    /** @var list<string> */
    public array $statements = [];

    /** Runs before each connection attempt, e.g. to simulate a concurrent save during a connection test. */
    public ?\Closure $beforeConnect = null;

    public function __construct(private readonly TestCase $test) {}

    /**
     * @return array{dsn: string, username: ?string, password: ?string, options: array<int, mixed>, read_timeout: string|false}|null
     */
    public function lastAttempt(): ?array
    {
        return $this->attempts === [] ? null : $this->attempts[array_key_last($this->attempts)];
    }

    public function resolver(): HostResolver
    {
        $fake = $this;

        return new class($fake) implements HostResolver
        {
            public function __construct(private FakeManagedDatabase $fake) {}

            public function resolve(string $hostname): array
            {
                return $this->fake->dns[$hostname] ?? [];
            }
        };
    }

    public function pdoFactory(): PdoFactory
    {
        $fake = $this;

        return new class($fake) extends PdoFactory
        {
            public function __construct(private FakeManagedDatabase $fake) {}

            public function make(string $dsn, ?string $username, #[\SensitiveParameter] ?string $password, array $options): PDO
            {
                return $this->fake->connect($dsn, $username, $password, $options);
            }
        };
    }

    /**
     * @param  array<int, mixed>  $options
     */
    public function connect(string $dsn, ?string $username, ?string $password, array $options): PDO
    {
        $this->attempts[] = ['dsn' => $dsn, 'username' => $username, 'password' => $password, 'options' => $options, 'read_timeout' => ini_get('mysqlnd.net_read_timeout')];
        if ($this->beforeConnect !== null) {
            ($this->beforeConnect)();
        }

        foreach ($this->refuse as $address) {
            if (str_contains($dsn, $address)) {
                throw new PDOException("Access denied for user '$username'", 2002);
            }
        }

        $pdo = (new \ReflectionMethod($this->test, 'createStub'))->invoke($this->test, PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql): int {
            $this->statements[] = $sql;

            return 0;
        });
        $pdo->method('query')->willReturnCallback(function (string $sql): PDOStatement {
            $this->statements[] = $sql;

            return $this->statement(match (true) {
                str_starts_with($sql, 'SHOW SESSION STATUS') => [['Ssl_cipher', $this->tls ? 'TLS_AES_256_GCM_SHA384' : '']],
                str_starts_with($sql, 'SHOW GRANTS') => array_map(fn (string $line): array => [$line], $this->grants),
                $sql === 'SHOW DATABASES' => array_map(fn (string $db): array => [$db], $this->databases),
                default => [['1']],
            });
        });

        return $pdo;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function statement(array $rows): PDOStatement
    {
        $statement = (new \ReflectionMethod($this->test, 'createStub'))->invoke($this->test, PDOStatement::class);
        $statement->method('fetchAll')->willReturnCallback(
            fn (int $mode = PDO::FETCH_DEFAULT): array => $mode === PDO::FETCH_COLUMN ? array_column($rows, 0) : $rows
        );
        $statement->method('fetch')->willReturnCallback(fn (): array|false => $rows[0] ?? false);

        return $statement;
    }
}
