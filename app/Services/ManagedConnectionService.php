<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ManagedConnection;
use App\Models\ManagedCredentialSecret;
use App\Models\Organization;
use App\Models\User;
use App\Support\ManagedCredentials;
use App\Support\ManagedDatabase\ConnectionRejectedException;
use App\Support\ManagedDatabase\ConnectionVersionChangedException;
use App\Support\ManagedDatabase\EgressDeniedException;
use App\Support\ManagedDatabase\PrivilegeInspector;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

/**
 * Lifecycle of an organization's managed database connections: create, test,
 * update, disable and reactivate. Every change that affects how Pane connects
 * is tested first (network rules, TLS, reachability, account privileges), and
 * nothing is saved when the test fails. Secrets go to the vault, never to the
 * connection row or the audit log.
 */
class ManagedConnectionService
{
    /** Fields whose change needs a new connection test. */
    private const array CONNECTIVITY_FIELDS = ['engine', 'host', 'port', 'database_name', 'username', 'tls_mode'];

    public function __construct(
        private readonly ManagedDatabaseConnector $connector,
        private readonly ManagedCredentialVault $vault,
        private readonly PrivilegeInspector $privileges,
        private readonly OrganizationTenancyService $tenancy,
        private readonly AuditEventService $audit,
    ) {}

    /**
     * @param  array{engine: string, name: string, description?: string|null, host: string, port: int, database_name: string, username: string, tls_mode: string, status?: string}  $profile
     * @param  array<string, string>  $secrets  password and optional certificates
     *
     * @throws ConnectionRejectedException when the test fails (nothing is saved)
     * @throws DomainException when the name is taken or the quota is exceeded
     */
    public function create(Organization $organization, User $actor, array $profile, #[\SensitiveParameter] array $secrets, ?string $requestId = null): ManagedConnection
    {
        $status = $profile['status'] ?? ManagedConnection::STATUS_ACTIVE;
        $this->assertNameAvailable($organization, $profile['name']);
        $connectionId = (string) Str::uuid();
        $this->probe($organization, $actor, $connectionId, $profile, $secrets, $requestId);

        $connection = DB::transaction(function () use ($organization, $actor, $profile, $secrets, $status, $connectionId): ManagedConnection {
            if ($status === ManagedConnection::STATUS_ACTIVE) {
                $this->tenancy->reserveDatabaseConnectionSlot($organization);
            }

            $secret = $this->vault->store($organization, $secrets, $connectionId);

            return ManagedConnection::query()->create([
                ...$profile,
                'connection_id' => $connectionId,
                'organization_id' => $organization->getKey(),
                'status' => $status,
                'managed_credential_secret_id' => $secret->managed_credential_secret_id,
                'last_tested_at' => now(),
                'last_test_reachable' => true,
                'last_test_privileges_valid' => true,
                'created_by_user_id' => $actor->getKey(),
            ]);
        });

        $this->record('managed_connection.created', $organization, $actor, $connection, $requestId);

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $changes  profile fields and/or status
     * @param  array<string, string>|null  $secrets  new secrets, or null to keep the current ones
     * @param  string  $expectedVersion  the versionTag the caller read; checked again under a row lock before saving
     *
     * @throws ConnectionRejectedException when a needed re-test fails (nothing is saved)
     * @throws ConnectionVersionChangedException when the connection changed during the re-test (nothing is saved)
     * @throws DomainException when the name is taken or reactivating exceeds the quota
     */
    public function update(ManagedConnection $connection, User $actor, array $changes, #[\SensitiveParameter] ?array $secrets, string $expectedVersion, ?string $requestId = null): ManagedConnection
    {
        $organization = $connection->organization()->firstOrFail();
        $target = [...$this->profileOf($connection), ...array_intersect_key($changes, array_flip([...self::CONNECTIVITY_FIELDS, 'name', 'description']))];
        $status = $changes['status'] ?? $connection->status;
        $previousStatus = $connection->status;

        if ($target['name'] !== $connection->name) {
            $this->assertNameAvailable($organization, $target['name'], $connection->getKey());
        }

        $reactivating = $status === ManagedConnection::STATUS_ACTIVE && ! $connection->isActive();
        $connectivityChanged = $secrets !== null
            || array_diff_assoc(array_intersect_key($target, array_flip(self::CONNECTIVITY_FIELDS)), $this->profileOf($connection)) !== [];

        if ($status === ManagedConnection::STATUS_ACTIVE && ($connectivityChanged || $reactivating)) {
            $testSecrets = $secrets ?? $this->currentSecrets($connection);
            $this->probe($organization, $actor, $connection->getKey(), $target, $testSecrets, $requestId);
        }

        $updated = DB::transaction(function () use ($connection, $organization, $target, $status, $secrets, $reactivating, $connectivityChanged, $expectedVersion): ManagedConnection {
            // The probe ran outside this transaction, so another request may have saved in the meantime.
            $current = ManagedConnection::query()->whereKey($connection->getKey())->lockForUpdate()->firstOrFail();
            if (! hash_equals($expectedVersion, $current->versionTag())) {
                throw new ConnectionVersionChangedException('The connection changed while it was being checked. Read it again and retry.');
            }

            if ($reactivating) {
                $this->tenancy->reserveDatabaseConnectionSlot($organization);
            } elseif ($status === ManagedConnection::STATUS_DISABLED && $current->isActive()) {
                $this->tenancy->releaseDatabaseConnectionSlot($organization);
            }

            $attributes = [...$target, 'status' => $status];

            if ($secrets !== null) {
                $previous = $current->secret()->first();
                $secret = $this->vault->store($organization, $secrets, $current->getKey());
                $attributes['managed_credential_secret_id'] = $secret->managed_credential_secret_id;
                $previous?->forceFill(['status' => ManagedCredentialSecret::STATUS_REVOKED])->save();
            }

            if ($status === ManagedConnection::STATUS_ACTIVE && ($connectivityChanged || $reactivating)) {
                $attributes += ['last_tested_at' => now(), 'last_test_reachable' => true, 'last_test_privileges_valid' => true];
            }

            $current->forceFill($attributes)->save();

            return $current->refresh();
        });

        $action = match (true) {
            $reactivating => 'managed_connection.reactivated',
            $status === ManagedConnection::STATUS_DISABLED && $previousStatus !== $status => 'managed_connection.disabled',
            default => 'managed_connection.updated',
        };
        $this->record($action, $organization, $actor, $updated, $requestId, array_keys($changes));

        return $updated;
    }

    public function disable(ManagedConnection $connection, User $actor, string $expectedVersion, ?string $requestId = null): ManagedConnection
    {
        if (! $connection->isActive()) {
            return $connection;
        }

        return $this->update($connection, $actor, ['status' => ManagedConnection::STATUS_DISABLED], null, $expectedVersion, $requestId);
    }

    /**
     * Tests the saved connection and records the result. Never throws for a
     * failed test: the result says what failed.
     *
     * @return array{reachable: bool, privileges_valid: bool, message: string|null, tested_at: string}
     */
    public function test(ManagedConnection $connection, User $actor, ?string $requestId = null): array
    {
        $organization = $connection->organization()->firstOrFail();
        $result = ['reachable' => false, 'privileges_valid' => false, 'message' => null];

        try {
            $this->probe($organization, $actor, $connection->getKey(), $this->profileOf($connection), $this->currentSecrets($connection), $requestId);
            $result = ['reachable' => true, 'privileges_valid' => true, 'message' => null];
        } catch (ConnectionRejectedException $exception) {
            $result['reachable'] = $exception->getCode() === 1;
            $result['message'] = $exception->getMessage();
        }

        $testedAt = now();
        $connection->forceFill([
            'last_tested_at' => $testedAt,
            'last_test_reachable' => $result['reachable'],
            'last_test_privileges_valid' => $result['privileges_valid'],
        ])->save();

        $this->audit->record('managed_connection.tested', $result['privileges_valid'] ? AuditEvent::OUTCOME_SUCCESS : AuditEvent::OUTCOME_FAILURE, [
            'real_actor' => $actor,
            'organization' => $organization,
            'connection_id' => $connection->getKey(),
            'request_id' => $requestId,
            'metadata' => ['reachable' => $result['reachable'], 'privileges_valid' => $result['privileges_valid'], 'reason' => $result['message']],
        ]);

        return [...$result, 'tested_at' => $testedAt->toJSON()];
    }

    /**
     * Opens the saved connection for reading, through the same checks as a
     * connection test (network rules, TLS, reachability, exact privileges).
     *
     * @throws ConnectionRejectedException with a safe message when any check fails
     */
    public function open(ManagedConnection $connection, User $actor, ?string $requestId = null): PDO
    {
        $organization = $connection->organization()->firstOrFail();

        return $this->probe($organization, $actor, $connection->getKey(), $this->profileOf($connection), $this->currentSecrets($connection), $requestId);
    }

    /**
     * Connects with the given profile and secrets, then checks the account's
     * privileges. Throws ConnectionRejectedException with a safe message;
     * code 1 means the database was reachable but the privileges are wrong.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, string>  $secrets
     */
    private function probe(Organization $organization, User $actor, string $connectionId, array $profile, #[\SensitiveParameter] array $secrets, ?string $requestId): PDO
    {
        $credentials = new ManagedCredentials([
            'host' => $profile['host'],
            'port' => $profile['port'],
            'database' => $profile['database_name'],
            'username' => $profile['username'],
            ...$secrets,
        ]);
        $context = ['organization' => $organization, 'actor' => $actor, 'connection_id' => $connectionId, 'request_id' => $requestId];

        try {
            $pdo = $this->connector->test((string) $profile['engine'], $credentials, $context, (string) $profile['tls_mode']);
        } catch (EgressDeniedException $exception) {
            throw new ConnectionRejectedException($exception->getMessage(), 0, $exception);
        } catch (RuntimeException $exception) {
            if ($exception instanceof ConnectionRejectedException) {
                throw $exception;
            }

            throw new ConnectionRejectedException($exception->getMessage(), 0, $exception);
        }

        $report = $this->privileges->inspect($pdo, (string) $profile['database_name']);

        if (! $report['valid']) {
            $parts = [];
            if ($report['missing'] !== []) {
                $parts[] = 'missing '.implode(', ', $report['missing']).' on `'.$profile['database_name'].'`';
            }
            if ($report['excessive'] !== []) {
                $parts[] = 'remove '.implode(', ', $report['excessive']);
            }

            throw new ConnectionRejectedException('The database account has the wrong privileges: '.implode('; ', $parts).'. Pane needs exactly SELECT, INSERT, UPDATE and DELETE on its own database.', 1);
        }

        return $pdo;
    }

    /**
     * @return array<string, string>
     */
    private function currentSecrets(ManagedConnection $connection): array
    {
        $secret = $connection->secret()->first();

        if (! $secret instanceof ManagedCredentialSecret) {
            throw new ConnectionRejectedException('This connection has no stored credentials; set a new password.');
        }

        return array_filter($this->vault->reveal($secret)->reveal(), 'is_string');
    }

    /**
     * @return array{engine: string, name: string, description: string|null, host: string, port: int, database_name: string, username: string, tls_mode: string}
     */
    private function profileOf(ManagedConnection $connection): array
    {
        return [
            'engine' => $connection->engine,
            'name' => $connection->name,
            'description' => $connection->description,
            'host' => $connection->host,
            'port' => $connection->port,
            'database_name' => $connection->database_name,
            'username' => $connection->username,
            'tls_mode' => $connection->tls_mode,
        ];
    }

    private function assertNameAvailable(Organization $organization, string $name, ?string $ignoreId = null): void
    {
        $taken = ManagedConnection::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw new DomainException('A connection with this name already exists in the organization.');
        }
    }

    /**
     * @param  list<string>  $changedFields
     */
    private function record(string $action, Organization $organization, User $actor, ManagedConnection $connection, ?string $requestId, array $changedFields = []): void
    {
        $this->audit->record($action, AuditEvent::OUTCOME_SUCCESS, [
            'real_actor' => $actor,
            'organization' => $organization,
            'connection_id' => $connection->getKey(),
            'request_id' => $requestId,
            'metadata' => [
                'engine' => $connection->engine,
                'host' => $connection->host,
                'port' => $connection->port,
                'database' => $connection->database_name,
                'tls_mode' => $connection->tls_mode,
                'status' => $connection->status,
                // Field names only, never values.
                'fields' => $changedFields,
            ],
        ]);
    }
}
