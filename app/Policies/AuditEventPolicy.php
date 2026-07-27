<?php

namespace App\Policies;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

class AuditEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPaneAdministrator();
    }

    public function viewForOrganization(User $user, Organization $organization): bool
    {
        $membership = $organization->activeMembershipFor($user);

        return $organization->isActive()
            && $membership instanceof OrganizationMembership
            && $membership->isAdministrator();
    }

    public function view(User $user, AuditEvent $event): bool
    {
        if ($user->isPaneAdministrator()) {
            return true;
        }

        if ($event->organization_id === null) {
            return false;
        }

        $organization = Organization::query()->find($event->organization_id);

        return $organization instanceof Organization
            && $this->viewForOrganization($user, $organization);
    }
}
