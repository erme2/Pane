<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CatalogObject;
use App\Models\CatalogRefresh;
use App\Models\ManagedConnection;
use App\Models\User;
use App\Support\ManagedDatabase\CatalogNormalizer;
use App\Support\ManagedDatabase\CatalogReader;
use App\Support\ManagedDatabase\CatalogUnavailableException;
use App\Support\ManagedDatabase\CatalogVersionChangedException;
use App\Support\ManagedDatabase\ConnectionRejectedException;
use DomainException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Catalog discovery for managed connections, and the descriptions Pane keeps
 * for discovered objects.
 *
 * The managed database is authoritative for structure: Pane never creates or
 * alters it. A refresh adds new objects, updates the structure and status of
 * known ones, and marks objects that are gone as `missing` (kept, with their
 * descriptions, rather than deleted). A renamed object shows up as a missing
 * old object plus a new one. Descriptions change only through
 * updateDescription(), with optimistic concurrency and an audit event.
 *
 * @phpstan-import-type DiscoveredObject from CatalogNormalizer
 */
class CatalogService
{
    /** Refuse catalogs larger than this rather than storing a partial snapshot. */
    public const int MAX_OBJECTS = 20000;

    private const array COMPARED_FIELDS = ['name', 'parent_id', 'status', 'structure', 'issues'];

    public function __construct(
        private readonly ManagedConnectionService $connections,
        private readonly CatalogReader $reader,
        private readonly CatalogNormalizer $normalizer,
        private readonly AuditEventService $audit,
    ) {}

    /**
     * Discovers the connection's database into the catalog. Runs in the
     * request; the returned refresh is `completed`.
     *
     * @throws CatalogUnavailableException when the database can't be reached or read (the refresh is saved as `failed`)
     */
    public function refresh(ManagedConnection $connection, User $actor, ?string $requestId = null): CatalogRefresh
    {
        $refresh = CatalogRefresh::query()->create([
            'organization_id' => $connection->organization_id,
            'connection_id' => $connection->getKey(),
            'status' => CatalogRefresh::STATUS_RUNNING,
            'requested_by_user_id' => $actor->getKey(),
            'started_at' => now(),
        ]);

        try {
            $discovered = $this->discover($connection, $actor, $requestId);
            $counts = DB::transaction(function () use ($connection, $discovered): array {
                // One refresh at a time per connection.
                ManagedConnection::query()->whereKey($connection->getKey())->lockForUpdate()->firstOrFail();

                return $this->apply($connection, $discovered);
            });
        } catch (CatalogUnavailableException $exception) {
            $refresh->forceFill(['status' => CatalogRefresh::STATUS_FAILED, 'failure_reason' => $exception->getMessage(), 'finished_at' => now()])->save();
            $this->record('catalog.refreshed', AuditEvent::OUTCOME_FAILURE, $connection, $actor, $requestId, ['refresh_id' => $refresh->getKey(), 'reason' => $exception->getMessage()]);

            throw $exception;
        }

        $refresh->forceFill(['status' => CatalogRefresh::STATUS_COMPLETED, 'counts' => $counts, 'finished_at' => now()])->save();
        $this->record('catalog.refreshed', AuditEvent::OUTCOME_SUCCESS, $connection, $actor, $requestId, ['refresh_id' => $refresh->getKey(), ...$counts]);

        return $refresh;
    }

    /**
     * @throws CatalogVersionChangedException when the object changed since the caller read it (nothing is saved)
     */
    public function updateDescription(CatalogObject $object, User $actor, ?string $description, string $expectedVersion, ?string $requestId = null): CatalogObject
    {
        $description = $description === null || trim($description) === '' ? null : $description;

        $updated = DB::transaction(function () use ($object, $description, $expectedVersion): CatalogObject {
            $current = CatalogObject::query()->whereKey($object->getKey())->lockForUpdate()->firstOrFail();
            if (! hash_equals($expectedVersion, $current->versionTag())) {
                throw new CatalogVersionChangedException('The catalog object changed. Read it again and retry.');
            }

            if ($current->description !== $description) {
                $current->forceFill(['description' => $description, 'revision' => $current->revision + 1])->save();
            }

            return $current;
        });

        $connection = $updated->connection()->firstOrFail();
        $this->record('catalog.description_updated', AuditEvent::OUTCOME_SUCCESS, $connection, $actor, $requestId, [
            'catalog_object_id' => $updated->getKey(),
            'kind' => $updated->kind,
            'name' => $updated->name,
            'description_length' => $description === null ? 0 : mb_strlen($description),
        ]);

        return $updated;
    }

    /**
     * Allows or stops row CRUD on a table. Only an available table that meets
     * the CRUD table contract can be enabled; a refresh that finds the table
     * missing or incompatible disables it again.
     *
     * @throws DomainException when enabling a table that isn't a CRUD candidate
     */
    public function setCrudEnabled(CatalogObject $table, User $actor, bool $enabled, ?string $requestId = null): CatalogObject
    {
        if ($table->kind !== CatalogObject::KIND_TABLE) {
            throw new DomainException('Only tables can be enabled for CRUD.');
        }
        if ($enabled && $table->status !== CatalogObject::STATUS_AVAILABLE) {
            throw new DomainException('Only an available table that meets the CRUD table contract can be enabled.');
        }

        if ($table->crud_enabled !== $enabled) {
            $table->forceFill(['crud_enabled' => $enabled, 'revision' => $table->revision + 1])->save();
            $this->record('catalog.crud_'.($enabled ? 'enabled' : 'disabled'), AuditEvent::OUTCOME_SUCCESS, $table->connection()->firstOrFail(), $actor, $requestId, [
                'catalog_object_id' => $table->getKey(),
                'name' => $table->name,
            ]);
        }

        return $table;
    }

    /**
     * @return list<DiscoveredObject>
     */
    private function discover(ManagedConnection $connection, User $actor, ?string $requestId): array
    {
        if (! $connection->isActive()) {
            throw new CatalogUnavailableException('The connection is disabled; reactivate it before refreshing the catalog.');
        }

        try {
            $pdo = $this->connections->open($connection, $actor, $requestId);
            $raw = $this->reader->read($pdo, $connection->database_name);
        } catch (ConnectionRejectedException $exception) {
            throw new CatalogUnavailableException($exception->getMessage(), 0, $exception);
        } catch (PDOException $exception) {
            // Driver messages can include names from the server; keep only the code.
            throw new CatalogUnavailableException('The catalog could not be read from the database (driver error '.$exception->getCode().').', 0, $exception);
        }

        if ($raw['schema'] === null) {
            throw new CatalogUnavailableException('The configured database was not found on the server.');
        }

        $discovered = $this->normalizer->normalize($raw, $connection->database_name);
        if (count($discovered) > self::MAX_OBJECTS) {
            throw new CatalogUnavailableException('The database has more than '.self::MAX_OBJECTS.' tables, columns and relationships; Pane cannot catalog it.');
        }

        return $discovered;
    }

    /**
     * @param  list<DiscoveredObject>  $discovered
     * @return array{added: int, changed: int, missing: int, unchanged: int}
     */
    private function apply(ManagedConnection $connection, array $discovered): array
    {
        $existing = CatalogObject::query()->where('connection_id', $connection->getKey())->get()->keyBy('object_key');
        $counts = ['added' => 0, 'changed' => 0, 'missing' => 0, 'unchanged' => 0];
        $ids = [];
        $seen = [];

        usort($discovered, fn (array $a, array $b): int => array_search($a['kind'], CatalogObject::KINDS, true) <=> array_search($b['kind'], CatalogObject::KINDS, true));

        foreach ($discovered as $item) {
            $seen[$item['key']] = true;
            $attributes = [
                'name' => $item['name'],
                'parent_id' => $item['parent_key'] === null ? null : ($ids[$item['parent_key']] ?? null),
                'status' => $item['status'],
                'structure' => $item['structure'],
                'issues' => $item['issues'],
            ];
            $object = $existing->get($item['key']);

            if (! $object instanceof CatalogObject) {
                $object = CatalogObject::query()->create([
                    ...$attributes,
                    'organization_id' => $connection->organization_id,
                    'connection_id' => $connection->getKey(),
                    'object_key' => $item['key'],
                    'kind' => $item['kind'],
                    'sort_rank' => (int) array_search($item['kind'], CatalogObject::KINDS, true),
                ]);
                $counts['added']++;
            } elseif ($this->differs($object, $attributes)) {
                $object->forceFill([
                    ...$attributes,
                    'missing_since' => null,
                    // Enabling CRUD is explicit: a table that stopped meeting the contract has to be enabled again.
                    'crud_enabled' => $object->crud_enabled && $item['status'] === CatalogObject::STATUS_AVAILABLE,
                    'revision' => $object->revision + 1,
                ])->save();
                $counts['changed']++;
            } else {
                $counts['unchanged']++;
            }

            $ids[$item['key']] = $object->getKey();
        }

        foreach ($existing as $key => $object) {
            if (isset($seen[$key]) || $object->status === CatalogObject::STATUS_MISSING) {
                continue;
            }

            $object->forceFill([
                'status' => CatalogObject::STATUS_MISSING,
                'missing_since' => now(),
                'crud_enabled' => false,
                'revision' => $object->revision + 1,
            ])->save();
            $counts['missing']++;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function differs(CatalogObject $object, array $attributes): bool
    {
        if ($object->status === CatalogObject::STATUS_MISSING) {
            return true;
        }

        foreach (self::COMPARED_FIELDS as $field) {
            if ($object->getAttribute($field) !== $attributes[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(string $action, string $outcome, ManagedConnection $connection, User $actor, ?string $requestId, array $metadata): void
    {
        $this->audit->record($action, $outcome, [
            'real_actor' => $actor,
            'organization' => $connection->organization()->first(),
            'connection_id' => $connection->getKey(),
            'request_id' => $requestId,
            'metadata' => $metadata,
        ]);
    }
}
