<?php

namespace Tests\Support;

use App\Support\ManagedDatabase\CatalogReader;
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

    /**
     * information_schema rows by CatalogReader query: schema, tables, columns, indexes, foreign_keys.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public array $catalog = [
        'schema' => [['schema_name' => 'shop', 'charset' => 'utf8mb4', 'collation_name' => 'utf8mb4_0900_ai_ci']],
        'tables' => [],
        'columns' => [],
        'indexes' => [],
        'foreign_keys' => [],
    ];

    /** @var list<array{sql: string, params: array<int, mixed>}> prepared catalog queries and their bound values */
    public array $catalogQueries = [];

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

        $pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $part = match ($sql) {
                CatalogReader::SCHEMA_SQL => 'schema',
                CatalogReader::TABLES_SQL => 'tables',
                CatalogReader::COLUMNS_SQL => 'columns',
                CatalogReader::INDEXES_SQL => 'indexes',
                CatalogReader::FOREIGN_KEYS_SQL => 'foreign_keys',
                default => throw new PDOException('Unexpected prepared statement'),
            };

            return $this->statement($this->catalog[$part], function (?array $params = null) use ($sql): bool {
                $this->catalogQueries[] = ['sql' => $sql, 'params' => $params ?? []];

                return true;
            });
        });

        return $pdo;
    }

    /**
     * Adds a table to the catalog. Columns are [name => column_type], e.g. ['id' => 'int', 'pane_membership_id' => 'char(36)'];
     * columns named in $nullable allow NULL. The first column is the primary key unless $primaryKey says otherwise.
     *
     * @param  array<string, string>  $columns
     * @param  list<string>  $nullable
     * @param  list<string>|null  $primaryKey
     * @param  list<string>  $indexed  leading columns of other indexes
     */
    public function addTable(string $table, array $columns, array $nullable = [], ?array $primaryKey = null, array $indexed = [], string $type = 'BASE TABLE'): void
    {
        $this->catalog['tables'][] = ['table_name' => $table, 'table_type' => $type, 'engine' => $type === 'BASE TABLE' ? 'InnoDB' : null];
        $ordinal = 0;
        foreach ($columns as $name => $columnType) {
            $dataType = strtolower((string) preg_split('/[\s(]/', $columnType)[0]);
            preg_match('/\((\d+)\)/', $columnType, $length);
            $this->catalog['columns'][] = [
                'table_name' => $table, 'column_name' => $name, 'ordinal' => ++$ordinal, 'data_type' => $dataType,
                'column_type' => $columnType, 'is_nullable' => in_array($name, $nullable, true) ? 'YES' : 'NO',
                'max_length' => in_array($dataType, ['char', 'varchar'], true) ? (int) ($length[1] ?? 255) : null,
                'numeric_precision' => null, 'numeric_scale' => null, 'extra' => '',
            ];
        }

        foreach ($primaryKey ?? [array_key_first($columns)] as $seq => $column) {
            $this->catalog['indexes'][] = ['table_name' => $table, 'index_name' => 'PRIMARY', 'non_unique' => 0, 'seq' => $seq + 1, 'column_name' => $column];
        }
        foreach ($indexed as $column) {
            $this->catalog['indexes'][] = ['table_name' => $table, 'index_name' => $table.'_'.$column.'_index', 'non_unique' => 1, 'seq' => 1, 'column_name' => $column];
        }
    }

    /** A table that meets the phase-one CRUD contract, plus any extra columns. @param array<string, string> $extra */
    public function addContractTable(string $table, array $extra = []): void
    {
        $this->addTable($table, [
            'id' => 'bigint unsigned', 'pane_membership_id' => 'char(36)', ...$extra,
            'created_at' => 'timestamp', 'updated_at' => 'timestamp', 'deleted_at' => 'timestamp',
        ], nullable: ['created_at', 'updated_at', 'deleted_at'], indexed: ['pane_membership_id']);
    }

    /** @param list<string> $columns @param list<string> $referencedColumns */
    public function addForeignKey(string $table, string $constraint, array $columns, string $referencedTable, array $referencedColumns, string $referencedSchema = 'shop'): void
    {
        foreach ($columns as $i => $column) {
            $this->catalog['foreign_keys'][] = [
                'constraint_name' => $constraint, 'table_name' => $table, 'column_name' => $column, 'ordinal' => $i + 1,
                'referenced_schema' => $referencedSchema, 'referenced_table' => $referencedTable, 'referenced_column' => $referencedColumns[$i],
            ];
        }
    }

    /** Removes a table and everything that belongs to it. */
    public function dropTable(string $table): void
    {
        foreach (['tables', 'columns', 'indexes', 'foreign_keys'] as $part) {
            $this->catalog[$part] = array_values(array_filter($this->catalog[$part], fn (array $row): bool => $row['table_name'] !== $table));
        }
    }

    /**
     * @param  list<array<int|string, mixed>>  $rows
     * @param  (\Closure(array<int, mixed>|null): bool)|null  $onExecute
     */
    private function statement(array $rows, ?\Closure $onExecute = null): PDOStatement
    {
        $statement = (new \ReflectionMethod($this->test, 'createStub'))->invoke($this->test, PDOStatement::class);
        $statement->method('fetchAll')->willReturnCallback(
            fn (int $mode = PDO::FETCH_DEFAULT): array => $mode === PDO::FETCH_COLUMN ? array_column($rows, 0) : $rows
        );
        $statement->method('fetch')->willReturnCallback(fn (): array|false => $rows[0] ?? false);
        if ($onExecute !== null) {
            $statement->method('execute')->willReturnCallback($onExecute);
        }

        return $statement;
    }
}
