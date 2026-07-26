<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPaneAdministrator();
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->isPaneAdministrator()
            || $organization->activeMembershipFor($user) instanceof OrganizationMembership;
    }

    public function access(User $user, Organization $organization): bool
    {
        return $organization->isActive()
            && $organization->activeMembershipFor($user) instanceof OrganizationMembership;
    }

    public function administer(User $user, Organization $organization): bool
    {
        $membership = $organization->activeMembershipFor($user);

        return $organization->isActive()
            && $membership instanceof OrganizationMembership
            && $membership->isAdministrator();
    }
}
