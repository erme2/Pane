<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\PaneAdminInvitation;
use App\Models\User;
use App\Support\PaneTable;
use App\Support\SettingsRegistry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaneAdminLifecycleService
{
    private const string BOOTSTRAP_LOCK_NAME = 'pane_admin_bootstrap';

    private const int TOKEN_BYTES = 40;

    public function __construct(
        private readonly AuditEventService $audit,
        private readonly SettingsService $settings,
    ) {}

    public function bootstrapFirstAdministrator(string $email, ?string $name = null): User
    {
        $email = $this->normalizeEmail($email);

        return DB::transaction(function () use ($email, $name): User {
            $this->lockPaneAdminBootstrap();

            $administrators = User::query()
                ->where('user_type_id', User::PANE_ADMINISTRATOR_USER_TYPE_ID)
                ->lockForUpdate()
                ->get();

            $matchingAdministrator = $administrators->firstWhere('email', $email);

            if ($matchingAdministrator instanceof User && $matchingAdministrator->isPaneAdministrator()) {
                return $matchingAdministrator;
            }

            if ($administrators->isNotEmpty()) {
                throw new DomainException('Pane administrator bootstrap is already complete.');
            }

            $user = $this->userForEmail($email);
            $user->forceFill([
                'user_type_id' => User::PANE_ADMINISTRATOR_USER_TYPE_ID,
                'name' => $name ?: ($user->name ?: $email),
                'email' => $email,
                'email_verified_at' => $user->email_verified_at ?: now(),
                'password' => $user->password ?: Hash::make(Str::random(40)),
                'is_active' => true,
            ])->save();

            $this->audit->record('installation.admin.bootstrap', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $user,
                'effective_user' => $user,
                'resource_ids' => [
                    'user_id' => $user->getKey(),
                ],
                'changed_columns' => ['user_type_id', 'is_active'],
                'metadata' => [
                    'email' => $email,
                ],
            ]);

            return $user;
        });
    }

    /**
     * @return array{invitation: PaneAdminInvitation, token: string}
     */
    public function invitePaneAdministrator(User $actor, string $email): array
    {
        $this->assertPaneAdministrator($actor);
        $email = $this->normalizeEmail($email);
        $this->assertEmailDoesNotBelongToActivePaneAdministrator($email);

        return DB::transaction(function () use ($actor, $email): array {
            $this->revokePendingInvitationsForEmail($email);

            [$invitation, $token] = $this->createInvitation($actor, $email);

            $this->audit->record('installation.admin.invite', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'resource_ids' => [
                    'pane_admin_invitation_id' => $invitation->getKey(),
                ],
                'metadata' => [
                    'email' => $email,
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
     * @return array{invitation: PaneAdminInvitation, token: string}
     */
    public function resendPaneAdministratorInvitation(User $actor, PaneAdminInvitation $invitation): array
    {
        $this->assertPaneAdministrator($actor);

        return DB::transaction(function () use ($actor, $invitation): array {
            $locked = $this->lockedInvitation($invitation);

            if ($locked->status === PaneAdminInvitation::STATUS_ACCEPTED) {
                throw new DomainException('Accepted Pane administrator invitations cannot be resent.');
            }

            $locked->forceFill([
                'status' => PaneAdminInvitation::STATUS_REVOKED,
                'revoked_at' => now(),
            ])->save();

            [$replacement, $token] = $this->createInvitation($actor, $locked->email);

            $this->audit->record('installation.admin.invitation.resend', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'resource_ids' => [
                    'pane_admin_invitation_id' => $replacement->getKey(),
                    'previous_pane_admin_invitation_id' => $locked->getKey(),
                ],
                'metadata' => [
                    'email' => $replacement->email,
                ],
            ]);

            return [
                'invitation' => $replacement,
                'token' => $token,
            ];
        });
    }

    public function revokePaneAdministratorInvitation(User $actor, PaneAdminInvitation $invitation): PaneAdminInvitation
    {
        $this->assertPaneAdministrator($actor);

        return DB::transaction(function () use ($actor, $invitation): PaneAdminInvitation {
            $locked = $this->lockedInvitation($invitation);

            if (! $locked->isPending()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => PaneAdminInvitation::STATUS_REVOKED,
                'revoked_at' => now(),
            ])->save();

            $this->audit->record('installation.admin.invitation.revoke', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'resource_ids' => [
                    'pane_admin_invitation_id' => $locked->getKey(),
                ],
                'metadata' => [
                    'email' => $locked->email,
                ],
            ]);

            return $locked;
        });
    }

    /**
     * @param array<string, mixed> $workOsUser
     * @param array<string, mixed> $authentication
     */
    public function acceptPaneAdministratorInvitation(
        string $token,
        array $workOsUser,
        array $authentication = []
    ): User {
        return $this->acceptPaneAdministratorInvitationHash($this->tokenHash($token), $workOsUser, $authentication);
    }

    /**
     * @param array<string, mixed> $workOsUser
     * @param array<string, mixed> $authentication
     */
    public function acceptPaneAdministratorInvitationHash(
        string $tokenHash,
        array $workOsUser,
        array $authentication = []
    ): User {
        $email = $this->normalizeEmail((string) ($workOsUser['email'] ?? ''));

        if (($workOsUser['email_verified'] ?? false) !== true) {
            throw new InvalidArgumentException('Pane administrator invitation requires a verified WorkOS email.');
        }

        if (! preg_match('/\A[a-f0-9]{64}\z/', $tokenHash)) {
            throw new InvalidArgumentException('Pane administrator invitation is invalid.');
        }

        $result = DB::transaction(function () use ($tokenHash, $workOsUser, $authentication, $email): User|InvalidArgumentException {
            $invitation = PaneAdminInvitation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof PaneAdminInvitation) {
                return new InvalidArgumentException('Pane administrator invitation is invalid.');
            }

            if ($invitation->status === PaneAdminInvitation::STATUS_ACCEPTED) {
                return new InvalidArgumentException('Pane administrator invitation was already accepted.');
            }

            if ($invitation->status === PaneAdminInvitation::STATUS_REVOKED) {
                return new InvalidArgumentException('Pane administrator invitation was revoked.');
            }

            if ($invitation->status === PaneAdminInvitation::STATUS_EXPIRED || $invitation->isExpired()) {
                $invitation->forceFill(['status' => PaneAdminInvitation::STATUS_EXPIRED])->save();

                return new InvalidArgumentException('Pane administrator invitation has expired.');
            }

            if (! hash_equals($invitation->email, $email)) {
                $this->audit->record('installation.admin.invitation.accept', AuditEvent::OUTCOME_DENIED, [
                    'resource_ids' => [
                        'pane_admin_invitation_id' => $invitation->getKey(),
                    ],
                    'metadata' => [
                        'expected_email' => $invitation->email,
                        'actual_email' => $email,
                    ],
                ]);

                return new InvalidArgumentException('Pane administrator invitation email does not match the WorkOS identity.');
            }

            $user = $this->syncInvitedPaneAdministrator($workOsUser, $authentication, $email);

            $invitation->forceFill([
                'status' => PaneAdminInvitation::STATUS_ACCEPTED,
                'accepted_by_user_id' => $user->getKey(),
                'accepted_at' => now(),
            ])->save();

            $this->audit->record('installation.admin.invitation.accept', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $user,
                'effective_user' => $user,
                'resource_ids' => [
                    'pane_admin_invitation_id' => $invitation->getKey(),
                    'user_id' => $user->getKey(),
                ],
                'changed_columns' => ['user_type_id', 'is_active'],
                'metadata' => [
                    'email' => $email,
                ],
            ]);

            return $user;
        });

        if ($result instanceof InvalidArgumentException) {
            throw $result;
        }

        return $result;
    }

    public function suspendPaneAdministrator(User $actor, User $administrator): User
    {
        $this->assertPaneAdministrator($actor);

        $result = DB::transaction(function () use ($actor, $administrator): User|DomainException {
            $locked = User::query()
                ->whereKey($administrator->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->user_type_id !== User::PANE_ADMINISTRATOR_USER_TYPE_ID) {
                throw new DomainException('Only Pane administrators can be suspended through Pane administrator lifecycle.');
            }

            if (! $locked->is_active) {
                return $locked;
            }

            if ($this->activePaneAdministratorCount() <= 1) {
                $this->audit->record('installation.admin.suspend', AuditEvent::OUTCOME_DENIED, [
                    'real_actor' => $actor,
                    'effective_user' => $actor,
                    'resource_ids' => [
                        'user_id' => $locked->getKey(),
                    ],
                    'metadata' => [
                        'reason' => 'final_active_pane_administrator',
                    ],
                ]);

                return new DomainException('Cannot suspend the final active Pane administrator.');
            }

            $locked->forceFill(['is_active' => false])->save();

            $this->audit->record('installation.admin.suspend', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'resource_ids' => [
                    'user_id' => $locked->getKey(),
                ],
                'changed_columns' => ['is_active'],
            ]);

            return $locked;
        });

        if ($result instanceof DomainException) {
            throw $result;
        }

        return $result;
    }

    public function reactivatePaneAdministrator(User $actor, User $administrator): User
    {
        $this->assertPaneAdministrator($actor);

        return DB::transaction(function () use ($actor, $administrator): User {
            $locked = User::query()
                ->whereKey($administrator->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->user_type_id !== User::PANE_ADMINISTRATOR_USER_TYPE_ID) {
                throw new DomainException('Only Pane administrators can be reactivated through Pane administrator lifecycle.');
            }

            $locked->forceFill(['is_active' => true])->save();

            $this->audit->record('installation.admin.reactivate', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'resource_ids' => [
                    'user_id' => $locked->getKey(),
                ],
                'changed_columns' => ['is_active'],
            ]);

            return $locked;
        });
    }

    private function syncInvitedPaneAdministrator(array $workOsUser, array $authentication, string $email): User
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

        $user->forceFill([
            'user_type_id' => User::PANE_ADMINISTRATOR_USER_TYPE_ID,
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
            'is_active' => true,
            'last_login_at' => now(),
        ])->save();

        return $user;
    }

    private function createInvitation(User $actor, string $email): array
    {
        $token = Str::random(self::TOKEN_BYTES);
        $expirySeconds = $this->settings->resolve(SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS);

        if (! is_int($expirySeconds)) {
            throw new DomainException('Pane administrator invitation expiry setting must resolve to an integer.');
        }

        $invitation = PaneAdminInvitation::query()->create([
            'email' => $email,
            'token_hash' => $this->tokenHash($token),
            'status' => PaneAdminInvitation::STATUS_PENDING,
            'invited_by_user_id' => $actor->getKey(),
            'expires_at' => now()->addSeconds($expirySeconds),
        ]);

        return [$invitation, $token];
    }

    private function lockedInvitation(PaneAdminInvitation $invitation): PaneAdminInvitation
    {
        return PaneAdminInvitation::query()
            ->whereKey($invitation->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function revokePendingInvitationsForEmail(string $email): void
    {
        PaneAdminInvitation::query()
            ->where('email', $email)
            ->where('status', PaneAdminInvitation::STATUS_PENDING)
            ->update([
                'status' => PaneAdminInvitation::STATUS_REVOKED,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function assertPaneAdministrator(User $actor): void
    {
        if (! $actor->isPaneAdministrator()) {
            throw new DomainException('Only active Pane administrators can manage Pane administrator lifecycle.');
        }
    }

    private function assertEmailDoesNotBelongToActivePaneAdministrator(string $email): void
    {
        $exists = User::query()
            ->where('email', $email)
            ->where('user_type_id', User::PANE_ADMINISTRATOR_USER_TYPE_ID)
            ->where('is_active', true)
            ->exists();

        if ($exists) {
            throw new DomainException('Email already belongs to an active Pane administrator.');
        }
    }

    private function activePaneAdministratorCount(): int
    {
        return User::query()
            ->where('user_type_id', User::PANE_ADMINISTRATOR_USER_TYPE_ID)
            ->where('is_active', true)
            ->lockForUpdate()
            ->count();
    }

    private function userForEmail(string $email): User
    {
        $user = User::query()
            ->where('email', $email)
            ->lockForUpdate()
            ->first();

        return $user instanceof User ? $user : new User([
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
        ]);
    }

    private function normalizeEmail(string $email): string
    {
        $normalized = strtolower(trim($email));

        if (! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Pane administrator email must be valid.');
        }

        return $normalized;
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function lockPaneAdminBootstrap(): void
    {
        $lock = DB::table(PaneTable::name(PaneTable::PANE_INSTALLATION_LOCKS))
            ->where('lock_name', self::BOOTSTRAP_LOCK_NAME)
            ->lockForUpdate()
            ->first();

        if ($lock === null) {
            throw new DomainException('Pane administrator bootstrap lock is missing.');
        }
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
