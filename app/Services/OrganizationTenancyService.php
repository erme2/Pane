<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OrganizationTenancyService
{
    public const string VERSION_CONFLICT_MESSAGE = 'Organization version does not match.';

    public function __construct(private readonly AuditEventService $audit) {}

    public function createOrganization(string $name, string $slug, int $databaseLimit = 1): Organization
    {
        $name = trim($name);
        $normalizedSlug = Str::slug($slug);
        $this->assertOrganizationName($name);
        $this->assertDatabaseLimit($databaseLimit);

        if ($normalizedSlug === '') {
            throw new InvalidArgumentException('Organization slug must contain at least one URL-safe character.');
        }

        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => $normalizedSlug,
            'status' => Organization::STATUS_ACTIVE,
            'database_limit' => $databaseLimit,
        ]);

        return $organization->refresh();
    }

    /**
     * @param  array{name?: string, slug?: string, status?: string, database_limit?: int}  $attributes
     */
    public function updateOrganization(User $actor, Organization $organization, array $attributes, ?string $expectedVersion = null): Organization
    {
        $this->assertPaneAdministrator($actor, 'Only active Pane administrators can manage organizations.');

        return DB::transaction(function () use ($actor, $organization, $attributes, $expectedVersion): Organization {
            $locked = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (is_string($expectedVersion) && ! hash_equals($expectedVersion, $locked->versionTag())) {
                throw new DomainException(self::VERSION_CONFLICT_MESSAGE);
            }

            $changes = [];

            if (array_key_exists('name', $attributes)) {
                $name = trim((string) $attributes['name']);
                $this->assertOrganizationName($name);
                $changes['name'] = $name;
            }

            if (array_key_exists('slug', $attributes)) {
                $slug = Str::slug((string) $attributes['slug']);

                if ($slug === '') {
                    throw new InvalidArgumentException('Organization slug must contain at least one URL-safe character.');
                }

                $changes['slug'] = $slug;
            }

            if (array_key_exists('status', $attributes)) {
                $status = (string) $attributes['status'];

                if (! in_array($status, Organization::STATUSES, true)) {
                    throw new InvalidArgumentException("Unsupported organization status [$status].");
                }

                $changes['status'] = $status;
            }

            if (array_key_exists('database_limit', $attributes)) {
                $limit = (int) $attributes['database_limit'];
                $this->assertDatabaseLimit($limit);
                $changes['database_limit'] = $limit;
            }

            $locked->forceFill($changes)->save();

            $this->audit->record('organization.update', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'organization' => $locked,
                'resource_ids' => ['organization_id' => $locked->getKey()],
                'changed_columns' => array_keys($changes),
                'metadata' => [
                    'status' => $locked->status,
                    'database_limit' => $locked->database_limit,
                    'over_database_limit' => $locked->isOverDatabaseLimit(),
                ],
            ]);

            return $locked;
        });
    }

    public function reserveDatabaseConnectionSlot(Organization $organization): Organization
    {
        return DB::transaction(function () use ($organization): Organization {
            $locked = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isActive()) {
                throw new DomainException('The organization is inactive.');
            }

            if ($locked->active_database_connections >= $locked->database_limit) {
                throw new DomainException('The organization database connection quota is exceeded.');
            }

            $locked->forceFill([
                'active_database_connections' => $locked->active_database_connections + 1,
            ])->save();

            return $locked;
        });
    }

    public function releaseDatabaseConnectionSlot(Organization $organization): Organization
    {
        return DB::transaction(function () use ($organization): Organization {
            $locked = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'active_database_connections' => max(0, $locked->active_database_connections - 1),
            ])->save();

            return $locked;
        });
    }

    public function addOrReactivateMembership(
        Organization $organization,
        User $user,
        string $role,
        ?User $inviter = null,
        ?Carbon $acceptedAt = null
    ): OrganizationMembership {
        $this->assertRole($role);

        return DB::transaction(function () use ($organization, $user, $role, $inviter, $acceptedAt): OrganizationMembership {
            $membership = OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($membership instanceof OrganizationMembership) {
                if (
                    $membership->isActive()
                    && $membership->isAdministrator()
                    && $role !== OrganizationMembership::ROLE_ADMINISTRATOR
                    && $this->activeOrganizationAdministratorCount($membership->organization_id) <= 1
                ) {
                    throw new DomainException('Cannot demote the final active organization administrator.');
                }

                $membership->forceFill([
                    'role' => $role,
                    'status' => OrganizationMembership::STATUS_ACTIVE,
                    'invited_by_user_id' => $inviter?->getKey(),
                    'accepted_at' => $acceptedAt ?? now(),
                    'suspended_at' => null,
                ])->save();

                return $membership;
            }

            $created = OrganizationMembership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'role' => $role,
                'status' => OrganizationMembership::STATUS_ACTIVE,
                'invited_by_user_id' => $inviter?->getKey(),
                'accepted_at' => $acceptedAt ?? now(),
            ]);

            return $created;
        });
    }

    public function suspendMembership(OrganizationMembership $membership): OrganizationMembership
    {
        return DB::transaction(function () use ($membership): OrganizationMembership {
            $locked = OrganizationMembership::query()
                ->whereKey($membership->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isActive()) {
                return $locked;
            }

            if ($locked->isAdministrator() && $this->activeOrganizationAdministratorCount($locked->organization_id) <= 1) {
                throw new DomainException('Cannot suspend the final active organization administrator.');
            }

            $locked->forceFill([
                'status' => OrganizationMembership::STATUS_SUSPENDED,
                'suspended_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function updateMembershipRole(OrganizationMembership $membership, string $role): OrganizationMembership
    {
        $this->assertRole($role);

        return DB::transaction(function () use ($membership, $role): OrganizationMembership {
            $locked = OrganizationMembership::query()
                ->whereKey($membership->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->role === $role) {
                return $locked;
            }

            if (
                $locked->isActive()
                && $locked->isAdministrator()
                && $role !== OrganizationMembership::ROLE_ADMINISTRATOR
                && $this->activeOrganizationAdministratorCount($locked->organization_id) <= 1
            ) {
                throw new DomainException('Cannot demote the final active organization administrator.');
            }

            $locked->forceFill(['role' => $role])->save();

            return $locked;
        });
    }

    public function deactivatePaneAdministrator(User $administrator): User
    {
        return DB::transaction(function () use ($administrator): User {
            $locked = User::query()
                ->whereKey($administrator->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPaneAdministrator()) {
                $locked->forceFill(['is_active' => false])->save();

                return $locked;
            }

            if ($this->activePaneAdministratorCount() <= 1) {
                throw new DomainException('Cannot deactivate the final active Pane administrator.');
            }

            $locked->forceFill(['is_active' => false])->save();

            return $locked;
        });
    }

    private function assertPaneAdministrator(User $actor, string $message): void
    {
        if (! $actor->isPaneAdministrator()) {
            throw new DomainException($message);
        }
    }

    private function assertOrganizationName(string $name): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Organization name cannot be empty.');
        }
    }

    private function assertDatabaseLimit(int $databaseLimit): void
    {
        if ($databaseLimit < 0) {
            throw new InvalidArgumentException('Organization database limit cannot be negative.');
        }
    }

    private function activeOrganizationAdministratorCount(string $organizationId): int
    {
        return OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('role', OrganizationMembership::ROLE_ADMINISTRATOR)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->lockForUpdate()
            ->count();
    }

    private function activePaneAdministratorCount(): int
    {
        return User::query()
            ->where('user_type_id', User::PANE_ADMINISTRATOR_USER_TYPE_ID)
            ->where('is_active', true)
            ->lockForUpdate()
            ->count();
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, OrganizationMembership::ROLES, true)) {
            throw new InvalidArgumentException("Unsupported organization membership role [$role].");
        }
    }
}
