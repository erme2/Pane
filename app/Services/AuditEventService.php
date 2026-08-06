<?php

namespace App\Services;

use App\Helpers\DefaultsHelper;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class AuditEventService
{
    use DefaultsHelper;

    private const string REDACTED = '[redacted]';

    /** @var array<int, string> */
    private const array SENSITIVE_KEY_PATTERNS = [
        'password',
        'secret',
        'token',
        'certificate',
        'private_key',
        'access_key',
        'bearer',
        'sql',
        'query',
        'statement',
        'row_values',
        'row_value',
        'values',
        'before',
        'after',
    ];

    /**
     * @param array{
     *     real_actor?: User|null,
     *     effective_user?: User|null,
     *     organization?: Organization|null,
     *     resource_ids?: array<string, mixed>,
     *     request_id?: string|null,
     *     client_metadata?: array<string, mixed>,
     *     impersonation_session_id?: string|null,
     *     connection_id?: string|null,
     *     table_name?: string|null,
     *     row_key?: string|null,
     *     changed_columns?: array<int, string>,
     *     metadata?: array<string, mixed>,
     * } $context
     */
    public function record(string $action, string $outcome, array $context = []): AuditEvent
    {
        $action = trim($action);

        if ($action === '') {
            throw new InvalidArgumentException('Audit event action cannot be empty.');
        }

        if (! in_array($outcome, AuditEvent::OUTCOMES, true)) {
            throw new InvalidArgumentException("Unsupported audit event outcome [$outcome].");
        }

        $realActor = $context['real_actor'] ?? null;
        $effectiveUser = $context['effective_user'] ?? null;
        $organization = $context['organization'] ?? null;

        $event = AuditEvent::query()->create([
            'occurred_at' => now(),
            'real_actor_user_id' => $realActor?->getKey(),
            'effective_user_id' => $effectiveUser?->getKey(),
            'organization_id' => $organization?->getKey(),
            'action' => $action,
            'outcome' => $outcome,
            'resource_ids' => $this->sanitizeMap($context['resource_ids'] ?? []),
            'request_id' => $context['request_id'] ?? null,
            'client_metadata' => $this->sanitizeMap($context['client_metadata'] ?? []),
            'impersonation_session_id' => $context['impersonation_session_id'] ?? null,
            'connection_id' => $context['connection_id'] ?? null,
            'table_name' => $context['table_name'] ?? null,
            'row_key' => $context['row_key'] ?? null,
            'changed_columns' => $this->normalizeChangedColumns($context['changed_columns'] ?? []),
            'metadata' => $this->sanitizeMap($context['metadata'] ?? []),
        ]);

        return $event;
    }

    /**
     * @return Collection<int, AuditEvent>
     */
    public function installationEventsFor(User $viewer, ?int $limit = null, int $offset = 0): Collection
    {
        if (! $viewer->isPaneAdministrator()) {
            throw new DomainException('Only Pane administrators can view installation audit events.');
        }

        $limit = $this->normalizePageLimit($limit);
        $this->assertPageOffset($offset);

        return AuditEvent::query()
            ->orderByDesc('occurred_at')
            ->orderByDesc('audit_event_id')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * @return Collection<int, AuditEvent>
     */
    public function organizationEventsFor(
        User $viewer,
        Organization $organization,
        ?int $limit = null,
        int $offset = 0
    ): Collection {
        $membership = $organization->activeMembershipFor($viewer);

        if (
            ! $organization->isActive()
            || ! $membership instanceof OrganizationMembership
            || ! $membership->isAdministrator()
        ) {
            throw new DomainException('Only active organization administrators can view organization audit events.');
        }

        $limit = $this->normalizePageLimit($limit);
        $this->assertPageOffset($offset);

        return AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('audit_event_id')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    private function normalizePageLimit(?int $limit): int
    {
        $limit ??= (int) $this->default('PAGINATION_LIMIT');
        $max = (int) $this->default('PAGINATION_MAX');

        if ($limit < 1 || $limit > $max) {
            throw new InvalidArgumentException("Audit event page limit must be between 1 and $max.");
        }

        return $limit;
    }

    private function assertPageOffset(int $offset): void
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Audit event page offset cannot be negative.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeMap(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if ($this->isSensitiveKey($key)) {
                $sanitized[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeMap($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function normalizeChangedColumns(array $columns): array
    {
        $normalized = [];

        foreach ($columns as $column) {
            $column = trim($column);

            if ($column !== '' && ! in_array($column, $normalized, true)) {
                $normalized[] = $column;
            }
        }

        return $normalized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
