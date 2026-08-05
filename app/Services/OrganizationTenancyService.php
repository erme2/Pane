<?php

namespace App\Services;

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
    public function createOrganization(string $name, string $slug, int $databaseLimit = 1): Organization
    {
        $normalizedSlug = Str::slug($slug);

        if ($normalizedSlug === '') {
            throw new InvalidArgumentException('Organization slug must contain at least one URL-safe character.');
        }

        $organization = Organization::query()->create([
            'name' => $name,
            'slug' => $normalizedSlug,
            'status' => Organization::STATUS_ACTIVE,
            'database_limit' => $databaseLimit,
        ]);

        return $organization;
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
