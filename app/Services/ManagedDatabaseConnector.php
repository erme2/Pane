<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Support\ManagedCredentials;
use App\Support\ManagedDatabase\EgressDeniedException;
use App\Support\ManagedDatabase\PdoFactory;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * The only way Pane opens a connection to a managed (organization) database.
 * Connection tests and runtime queries both come through here, so every
 * attempt is checked by ManagedDatabaseEgressPolicy first and then made to a
 * pinned, checked IP address (never the hostname), with the installation's
 * connect and query timeouts.
 */
class ManagedDatabaseConnector
{
    public const array DRIVERS = ['mysql', 'mariadb'];

    public function __construct(
        private readonly ManagedDatabaseEgressPolicy $policy,
        private readonly PdoFactory $pdo,
    ) {}

    /**
     * Opens a connection for runtime queries.
     *
     * @param  array{organization?: Organization|null, actor?: User|null, connection_id?: string|null, request_id?: string|null}  $context
     *
     * @throws EgressDeniedException when the destination is not allowed
     * @throws RuntimeException when no checked address accepts the connection
     */
    public function connect(string $driver, ManagedCredentials $credentials, array $context = [], string $purpose = 'query'): PDO
    {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException("Unsupported managed database driver [$driver].");
        }

        $host = (string) $credentials->get('host', '');
        $port = (int) $credentials->get('port', 3306);
        $decision = $this->policy->authorize($host, $port, [...$context, 'purpose' => $purpose]);

        if (! $decision->allowed) {
            throw new EgressDeniedException($decision);
        }

        $timeouts = $this->policy->timeouts();
        $database = (string) $credentials->get('database', '');
        $lastError = null;

        foreach ($decision->addresses as $address) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d%s;charset=utf8mb4',
                str_contains($address, ':') ? "[$address]" : $address,
                $decision->port,
                $database !== '' ? ';dbname='.$database : '',
            );

            try {
                $pdo = $this->pdo->make($dsn, $this->nullableString($credentials->get('username')), $this->nullableString($credentials->get('password')), [
                    PDO::ATTR_TIMEOUT => $timeouts['connect'],
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $exception) {
                // Driver messages can echo the user name; keep only the code.
                $lastError = (string) $exception->getCode();

                continue;
            }

            $pdo->exec($driver === 'mariadb'
                ? sprintf('SET SESSION max_statement_time = %d', $timeouts['query'])
                : sprintf('SET SESSION max_execution_time = %d', $timeouts['query'] * 1000));

            return $pdo;
        }

        throw new RuntimeException("Could not connect to the managed database at {$decision->host}:{$decision->port} (driver error {$lastError}).");
    }

    /**
     * Connection test: connects through the same path and runs `SELECT 1`.
     *
     * @param  array{organization?: Organization|null, actor?: User|null, connection_id?: string|null, request_id?: string|null}  $context
     */
    public function test(string $driver, ManagedCredentials $credentials, array $context = []): void
    {
        $this->connect($driver, $credentials, $context, 'connection_test')->query('SELECT 1');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
