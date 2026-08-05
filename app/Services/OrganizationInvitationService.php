<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\SettingsRegistry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OrganizationInvitationService
{
    public const string VERSION_CONFLICT_MESSAGE = 'Organization invitation version does not match.';

    private const int TOKEN_BYTES = 40;

    public function __construct(
        private readonly AuditEventService $audit,
        private readonly SettingsService $settings,
        private readonly OrganizationTenancyService $tenancy,
    ) {}

    /**
     * @return array{invitation: OrganizationInvitation, token: string}
     */
    public function inviteOrganizationMember(
        User $actor,
        Organization $organization,
        string $email,
        string $role
    ): array {
        $email = $this->normalizeEmail($email);
        $this->assertRole($role);

        return DB::transaction(function () use ($actor, $organization, $email, $role): array {
            $organization = $this->lockedOrganizationForAdministrator($actor, $organization);
            $this->assertEmailDoesNotBelongToActiveMembership($organization, $email);
            $this->revokePendingInvitationsForEmail($organization, $email);

            [$invitation, $token] = $this->createInvitation($actor, $organization, $email, $role);

            $this->audit->record('organization.membership.invite', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'organization' => $organization,
                'resource_ids' => [
                    'organization_invitation_id' => $invitation->getKey(),
                    'organization_id' => $organization->getKey(),
                ],
                'metadata' => [
                    'email' => $email,
                    'role' => $role,
                    'expires_at' => $invitation->expires_at->toJSON(),
                ],
            ]);

            return [
                'invitation' => $invitation,
                'token' => $token,
            ];
        });
    }

    /**
     * @return array{invitation: OrganizationInvitation, token: string}
     */
    public function bootstrapOrganizationAdministratorInvitation(
        User $actor,
        Organization $organization,
        string $email,
        string $role
    ): array {
        $email = $this->normalizeEmail($email);

        if ($role !== OrganizationMembership::ROLE_ADMINISTRATOR) {
            throw new InvalidArgumentException('Bootstrap organization invitations must use the administrator role.');
        }

        return DB::transaction(function () use ($actor, $organization, $email, $role): array {
            $organization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->first();

            if (! $organization instanceof Organization || ! $organization->isActive()) {
                throw new DomainException('The configured Latte organization is inactive.');
            }

            $this->revokePendingInvitationsForEmail($organization, $email);

            [$invitation, $token] = $this->createInvitation($actor, $organization, $email, $role);

            $this->audit->record('organization.membership.invite', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'organization' => $organization,
                'resource_ids' => [
                    'organization_invitation_id' => $invitation->getKey(),
                    'organization_id' => $organization->getKey(),
                ],
                'metadata' => [
                    'email' => $email,
                    'role' => $role,
                    'expires_at' => $invitation->expires_at->toJSON(),
                    'source' => 'latte_bootstrap',
                ],
            ]);

            return [
                'invitation' => $invitation,
                'token' => $token,
            ];
        });
    }

    /**
     * @return array{invitation: OrganizationInvitation, token: string}
     */
    public function resendOrganizationInvitation(
        User $actor,
        Organization $organization,
        OrganizationInvitation $invitation,
        string $expectedVersion
    ): array {
        return DB::transaction(function () use ($actor, $organization, $invitation, $expectedVersion): array {
            $organization = $this->lockedOrganizationForAdministrator($actor, $organization);
            $locked = $this->lockedInvitation($organization, $invitation);
            $this->assertExpectedVersion($locked, $expectedVersion);

            if ($locked->status === OrganizationInvitation::STATUS_ACCEPTED) {
                throw new DomainException('Accepted organization invitations cannot be resent.');
            }

            $this->assertEmailDoesNotBelongToActiveMembership($organization, $locked->email);

            $locked->forceFill([
                'status' => OrganizationInvitation::STATUS_REVOKED,
                'revoked_at' => now(),
            ])->save();

            [$replacement, $token] = $this->createInvitation($actor, $organization, $locked->email, $locked->role);

            $this->audit->record('organization.membership.invitation.resend', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'organization' => $organization,
                'resource_ids' => [
                    'organization_invitation_id' => $replacement->getKey(),
                    'previous_organization_invitation_id' => $locked->getKey(),
                    'organization_id' => $organization->getKey(),
                ],
                'metadata' => [
                    'email' => $replacement->email,
                    'role' => $replacement->role,
                ],
            ]);

            return [
                'invitation' => $replacement,
                'token' => $token,
            ];
        });
    }

    public function revokeOrganizationInvitation(
        User $actor,
        Organization $organization,
        OrganizationInvitation $invitation,
        string $expectedVersion
    ): OrganizationInvitation {
        return DB::transaction(function () use ($actor, $organization, $invitation, $expectedVersion): OrganizationInvitation {
            $organization = $this->lockedOrganizationForAdministrator($actor, $organization);
            $locked = $this->lockedInvitation($organization, $invitation);
            $this->assertExpectedVersion($locked, $expectedVersion);

            if (! $locked->isPending()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => OrganizationInvitation::STATUS_REVOKED,
                'revoked_at' => now(),
            ])->save();

            $this->audit->record('organization.membership.invitation.revoke', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'organization' => $organization,
                'resource_ids' => [
                    'organization_invitation_id' => $locked->getKey(),
                    'organization_id' => $organization->getKey(),
                ],
                'metadata' => [
                    'email' => $locked->email,
                    'role' => $locked->role,
                ],
            ]);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $workOsUser
     * @param  array<string, mixed>  $authentication
     */
    public function acceptOrganizationInvitation(
        Organization $organization,
        string $token,
        array $workOsUser,
        array $authentication = []
    ): User {
        return $this->acceptOrganizationInvitationHash(
            $organization,
            $this->tokenHash($token),
            $workOsUser,
            $authentication
        );
    }

    /**
     * @param  array<string, mixed>  $workOsUser
     * @param  array<string, mixed>  $authentication
     */
    public function acceptOrganizationInvitationHash(
        Organization $organization,
        string $tokenHash,
        array $workOsUser,
        array $authentication = []
    ): User {
        $email = $this->normalizeEmail((string) ($workOsUser['email'] ?? ''));

        if (($workOsUser['email_verified'] ?? false) !== true) {
            throw new InvalidArgumentException('Organization invitation requires a verified WorkOS email.');
        }

        if (! preg_match('/\A[a-f0-9]{64}\z/', $tokenHash)) {
            throw new InvalidArgumentException('Organization invitation is invalid.');
        }

        $result = DB::transaction(function () use ($organization, $tokenHash, $workOsUser, $authentication, $email): User|InvalidArgumentException {
            $organization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->first();

            if (! $organization instanceof Organization || ! $organization->isActive()) {
                throw new InvalidArgumentException('The application organization is inactive.');
            }

            $invitation = OrganizationInvitation::query()
                ->where('organization_id', $organization->getKey())
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof OrganizationInvitation) {
                return new InvalidArgumentException('Organization invitation is invalid.');
            }

            if ($invitation->status === OrganizationInvitation::STATUS_ACCEPTED) {
                return new InvalidArgumentException('Organization invitation was already accepted.');
            }

            if ($invitation->status === OrganizationInvitation::STATUS_REVOKED) {
                return new InvalidArgumentException('Organization invitation was revoked.');
            }

            if ($invitation->status === OrganizationInvitation::STATUS_EXPIRED || $invitation->isExpired()) {
                $invitation->forceFill(['status' => OrganizationInvitation::STATUS_EXPIRED])->save();

                return new InvalidArgumentException('Organization invitation has expired.');
            }

            if (! hash_equals($invitation->email, $email)) {
                $this->audit->record('organization.membership.invitation.accept', AuditEvent::OUTCOME_DENIED, [
                    'organization' => $organization,
                    'resource_ids' => [
                        'organization_invitation_id' => $invitation->getKey(),
                        'organization_id' => $organization->getKey(),
                    ],
                    'metadata' => [
                        'expected_email' => $invitation->email,
                        'actual_email' => $email,
                    ],
                ]);

                return new InvalidArgumentException('Organization invitation email does not match the WorkOS identity.');
            }

            $user = $this->invitedOrganizationUser($workOsUser, $email);

            if (
                $user->exists
                && (int) $user->user_type_id === User::PANE_ADMINISTRATOR_USER_TYPE_ID
                && ! (bool) $user->is_active
            ) {
                throw new InvalidArgumentException('Pane account is inactive.');
            }

            $user = $this->syncInvitedOrganizationUser($user, $workOsUser, $authentication, $email);
            $activeMembership = OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($activeMembership instanceof OrganizationMembership) {
                throw new InvalidArgumentException('Organization membership already exists.');
            }

            $membership = $this->tenancy->addOrReactivateMembership(
                $organization,
                $user,
                $invitation->role,
                $invitation->inviter,
                now()
            );

            $invitation->forceFill([
                'status' => OrganizationInvitation::STATUS_ACCEPTED,
                'accepted_by_user_id' => $user->getKey(),
                'accepted_at' => now(),
            ])->save();

            $this->audit->record('organization.membership.invitation.accept', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $user,
                'effective_user' => $user,
                'organization' => $organization,
                'resource_ids' => [
                    'organization_invitation_id' => $invitation->getKey(),
                    'organization_id' => $organization->getKey(),
                    'membership_id' => $membership->getKey(),
                    'user_id' => $user->getKey(),
                ],
                'changed_columns' => ['role', 'status', 'accepted_at'],
                'metadata' => [
                    'email' => $email,
                    'role' => $membership->role,
                ],
            ]);

            return $user;
        });

        if ($result instanceof InvalidArgumentException) {
            throw $result;
        }

        return $result;
    }

    public function hasOrganizationInvitationHash(Organization $organization, string $tokenHash): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $tokenHash) === 1
            && OrganizationInvitation::query()
                ->where('organization_id', $organization->getKey())
                ->where('token_hash', $tokenHash)
                ->exists();
    }

    private function createInvitation(
        User $actor,
        Organization $organization,
        string $email,
        string $role
    ): array {
        $token = Str::random(self::TOKEN_BYTES);
        $expirySeconds = $this->settings->resolve(
            SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
            $organization
        );

        if (! is_int($expirySeconds)) {
            throw new DomainException('Organization invitation expiry setting must resolve to an integer.');
        }

        $invitation = OrganizationInvitation::query()->create([
            'organization_id' => $organization->getKey(),
            'email' => $email,
            'token_hash' => $this->tokenHash($token),
            'role' => $role,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'invited_by_user_id' => $actor->getKey(),
            'expires_at' => now()->addSeconds($expirySeconds),
        ]);

        return [$invitation, $token];
    }

    private function lockedInvitation(Organization $organization, OrganizationInvitation $invitation): OrganizationInvitation
    {
        return OrganizationInvitation::query()
            ->whereKey($invitation->getKey())
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertExpectedVersion(OrganizationInvitation $invitation, string $expectedVersion): void
    {
        if (! hash_equals($expectedVersion, $invitation->versionTag())) {
            throw new DomainException(self::VERSION_CONFLICT_MESSAGE);
        }
    }

    private function revokePendingInvitationsForEmail(Organization $organization, string $email): void
    {
        OrganizationInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->where('email', $email)
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->update([
                'status' => OrganizationInvitation::STATUS_REVOKED,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function assertEmailDoesNotBelongToActiveMembership(Organization $organization, string $email): void
    {
        $exists = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->exists();

        if ($exists) {
            throw new DomainException('Email already belongs to an active organization membership.');
        }
    }

    private function lockedOrganizationForAdministrator(User $actor, Organization $organization): Organization
    {
        $lockedOrganization = Organization::query()
            ->whereKey($organization->getKey())
            ->lockForUpdate()
            ->first();

        $membership = $lockedOrganization instanceof Organization
            ? OrganizationMembership::query()
                ->where('organization_id', $lockedOrganization->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first()
            : null;

        if (
            ! $lockedOrganization instanceof Organization
            || ! $lockedOrganization->isActive()
            || ! $membership instanceof OrganizationMembership
            || ! $membership->isAdministrator()
        ) {
            throw new DomainException('Only active organization administrators can manage organization invitations.');
        }

        return $lockedOrganization;
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, OrganizationMembership::ROLES, true)) {
            throw new InvalidArgumentException("Unsupported organization membership role [$role].");
        }
    }

    private function invitedOrganizationUser(array $workOsUser, string $email): User
    {
        $user = User::query()
            ->where(function ($query) use ($workOsUser, $email): void {
                if (filled($workOsUser['id'] ?? null)) {
                    $query->where('workos_id', $workOsUser['id'])
                        ->orWhere('email', $email);

                    return;
                }

                $query->where('email', $email);
            })
            ->lockForUpdate()
            ->first();

        if (! $user instanceof User) {
            $user = new User([
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        return $user;
    }

    private function syncInvitedOrganizationUser(
        User $user,
        array $workOsUser,
        array $authentication,
        string $email
    ): User {
        $user->forceFill([
            'user_type_id' => $user->user_type_id ?: User::STANDARD_USER_TYPE_ID,
            'name' => $this->workOsDisplayName($workOsUser, $user->name ?: $email),
            'email' => $email,
            'email_verified_at' => now(),
            'workos_id' => $workOsUser['id'] ?? $user->workos_id,
            'workos_organization_id' => $authentication['organization_id'] ?? null,
            'details' => array_replace_recursive($user->details ?? [], [
                'workos' => [
                    'first_name' => $workOsUser['first_name'] ?? null,
                    'last_name' => $workOsUser['last_name'] ?? null,
                    'profile_picture_url' => $workOsUser['profile_picture_url'] ?? null,
                    'external_id' => $workOsUser['external_id'] ?? null,
                    'authentication_method' => $authentication['authentication_method'] ?? null,
                ],
            ]),
            'is_active' => $this->shouldActivateInvitedUser($user),
            'last_login_at' => now(),
        ])->save();

        return $user;
    }

    private function shouldActivateInvitedUser(User $user): bool
    {
        if (
            $user->exists
            && (int) $user->user_type_id === User::PANE_ADMINISTRATOR_USER_TYPE_ID
            && ! (bool) $user->is_active
        ) {
            return false;
        }

        return true;
    }

    private function normalizeEmail(string $email): string
    {
        $normalized = strtolower(trim($email));

        if (! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Organization invitation email must be valid.');
        }

        return $normalized;
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function workOsDisplayName(array $workOsUser, string $fallback): string
    {
        $name = trim(implode(' ', array_filter([
            $workOsUser['first_name'] ?? null,
            $workOsUser['last_name'] ?? null,
        ])));

        return $name !== '' ? $name : $fallback;
    }
}
