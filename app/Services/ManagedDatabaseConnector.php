<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use App\Support\ManagedCredentials;
use App\Support\ManagedDatabase\ConnectionRejectedException;
use App\Support\ManagedDatabase\EgressDeniedException;
use App\Support\ManagedDatabase\PdoFactory;
use InvalidArgumentException;
use PDO;
use Pdo\Mysql;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * The only way Pane opens a connection to a managed (organization) database.
 * Connection tests and runtime queries both come through here, so every
 * attempt is checked by ManagedDatabaseEgressPolicy first and then made to a
 * pinned, checked IP address (never the hostname), with the installation's
 * connect and query timeouts.
 *
 * Query timeout: MariaDB's `max_statement_time` stops any statement. MySQL's
 * `max_execution_time` only stops SELECT, so lock waits are bounded too
 * (`innodb_lock_wait_timeout`, `lock_wait_timeout`), and the client stops
 * waiting for a reply after the query timeout (`mysqlnd.net_read_timeout`),
 * which releases the Pane worker. That client timeout is not a guarantee for
 * writes: a MySQL write that is already running may still commit on the server
 * after Pane reports a timeout, so the outcome is unknown. Mutating queries must
 * not be retried blindly, and no caller may rely on a strict write timeout until
 * an operation-status check can reconcile the outcome.
 *
 * TLS modes: `required` encrypts the connection (the certificate isn't
 * checked, because Pane connects to the IP address); `disabled` is allowed
 * only for hosts an installation allow rule opened (same server or private
 * network). `verify_ca` and `verify_identity` aren't supported yet.
 */
class ManagedDatabaseConnector
{
    public const array DRIVERS = ['mysql', 'mariadb'];

    public const array SUPPORTED_TLS_MODES = ['disabled', 'required'];

    /** Seconds the client waits for a reply beyond the query timeout, so the server's own timeout reports first. */
    private const int READ_TIMEOUT_MARGIN = 2;

    public function __construct(
        private readonly ManagedDatabaseEgressPolicy $policy,
        private readonly PdoFactory $pdo,
    ) {}

    /**
     * Opens a connection. Credentials hold host, port, database, username,
     * password and optionally client_certificate / client_private_key (PEM).
     *
     * @param  array{organization?: Organization|null, actor?: User|null, connection_id?: string|null, request_id?: string|null}  $context
     *
     * @throws EgressDeniedException when the destination is not allowed
     * @throws ConnectionRejectedException when the TLS mode isn't allowed for this destination
     * @throws RuntimeException when no checked address accepts the connection
     */
    public function connect(
        string $driver,
        ManagedCredentials $credentials,
        array $context = [],
        string $purpose = 'query',
        string $tlsMode = 'required',
    ): PDO {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException("Unsupported managed database driver [$driver].");
        }

        if (! in_array($tlsMode, self::SUPPORTED_TLS_MODES, true)) {
            throw new ConnectionRejectedException("TLS mode [$tlsMode] is not supported yet: use required (or disabled for a database on the same server or a private network).");
        }

        $host = (string) $credentials->get('host', '');
        $port = (int) $credentials->get('port', 3306);
        $decision = $this->policy->authorize($host, $port, [...$context, 'purpose' => $purpose]);

        if (! $decision->allowed) {
            throw new EgressDeniedException($decision);
        }

        if ($tlsMode === 'disabled' && ! $this->policy->isPrivateDestination($decision)) {
            throw new ConnectionRejectedException("Unencrypted connections are only allowed to the same server or a private network; use TLS mode required for {$decision->host}.");
        }

        $timeouts = $this->policy->timeouts();
        $database = (string) $credentials->get('database', '');
        $tempFiles = [];
        $lastError = null;
        // Read when the connection opens, so it only needs to be set around make().
        $previousReadTimeout = ini_get('mysqlnd.net_read_timeout');

        try {
            $options = [
                PDO::ATTR_TIMEOUT => $timeouts['connect'],
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            if ($tlsMode === 'required') {
                $options += $this->tlsOptions($credentials, $tempFiles);
            }

            foreach ($decision->addresses as $address) {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d%s;charset=utf8mb4',
                    str_contains($address, ':') ? "[$address]" : $address,
                    $decision->port,
                    $database !== '' ? ';dbname='.$database : '',
                );

                try {
                    ini_set('mysqlnd.net_read_timeout', (string) ($timeouts['query'] + self::READ_TIMEOUT_MARGIN));
                    $pdo = $this->pdo->make($dsn, $this->nullableString($credentials->get('username')), $this->nullableString($credentials->get('password')), $options);
                } catch (PDOException $exception) {
                    // Driver messages can echo the user name; keep only the code.
                    $lastError = (string) $exception->getCode();

                    continue;
                } finally {
                    if ($previousReadTimeout !== false) {
                        ini_set('mysqlnd.net_read_timeout', $previousReadTimeout);
                    }
                }

                if ($tlsMode === 'required' && ! $this->usesTls($pdo)) {
                    throw new ConnectionRejectedException("The database at {$decision->host} did not accept an encrypted connection.");
                }

                $pdo->exec($driver === 'mariadb'
                    ? sprintf('SET SESSION max_statement_time = %d', $timeouts['query'])
                    : sprintf('SET SESSION max_execution_time = %d', $timeouts['query'] * 1000));
                $pdo->exec(sprintf('SET SESSION innodb_lock_wait_timeout = %1$d, lock_wait_timeout = %1$d', $timeouts['query']));

                return $pdo;
            }
        } finally {
            foreach ($tempFiles as $file) {
                @unlink($file);
            }
        }

        throw new RuntimeException("Could not connect to the managed database at {$decision->host}:{$decision->port} (driver error {$lastError}).");
    }

    /**
     * Connection test: connects through the same path and runs `SELECT 1`.
     *
     * @param  array{organization?: Organization|null, actor?: User|null, connection_id?: string|null, request_id?: string|null}  $context
     */
    public function test(string $driver, ManagedCredentials $credentials, array $context = [], string $tlsMode = 'required'): PDO
    {
        $pdo = $this->connect($driver, $credentials, $context, 'connection_test', $tlsMode);
        $pdo->query('SELECT 1');

        return $pdo;
    }

    /**
     * Options that make the driver negotiate TLS without checking the
     * certificate's host name (Pane connects to an IP address).
     *
     * @param  list<string>  $tempFiles
     * @return array<int, mixed>
     */
    private function tlsOptions(ManagedCredentials $credentials, array &$tempFiles): array
    {
        $ca = openssl_get_cert_locations()['default_cert_file'] ?? null;
        $options = [
            $this->mysqlAttribute('ATTR_SSL_VERIFY_SERVER_CERT') => false,
        ];

        // The driver only starts TLS when a TLS file is set; the system CA bundle works for that.
        if (is_string($ca) && is_file($ca)) {
            $options[$this->mysqlAttribute('ATTR_SSL_CA')] = $ca;
        }

        $certificate = $credentials->get('client_certificate');
        $key = $credentials->get('client_private_key');
        if (is_string($certificate) && $certificate !== '' && is_string($key) && $key !== '') {
            $options[$this->mysqlAttribute('ATTR_SSL_CERT')] = $this->tempFile($certificate, $tempFiles);
            $options[$this->mysqlAttribute('ATTR_SSL_KEY')] = $this->tempFile($key, $tempFiles);
        }

        return $options;
    }

    private function usesTls(PDO $pdo): bool
    {
        try {
            $statement = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
            $row = $statement === false ? false : $statement->fetch(PDO::FETCH_NUM);
        } catch (Throwable) {
            return false;
        }

        return is_array($row) && is_string($row[1] ?? null) && $row[1] !== '';
    }

    private function mysqlAttribute(string $name): int
    {
        return (int) constant(Mysql::class.'::'.$name);
    }

    /**
     * @param  list<string>  $tempFiles
     */
    private function tempFile(#[\SensitiveParameter] string $contents, array &$tempFiles): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pane-tls-');
        if ($path === false) {
            throw new RuntimeException('Could not prepare the TLS files for the managed database connection.');
        }
        chmod($path, 0600);
        file_put_contents($path, $contents);
        $tempFiles[] = $path;

        return $path;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
