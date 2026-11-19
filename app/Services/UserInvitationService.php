<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\SettingsRegistry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Invitations for standard users. Only Pane administrators invite; accepting one creates the user.
 * Administrator invitations stay in PaneAdminLifecycleService.
 */
class UserInvitationService
{
    private const int TOKEN_BYTES = 40;

    public function __construct(
        private readonly AuditEventService $audit,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{invitation: UserInvitation, token: string}
     */
    public function invite(User $actor, string $email): array
    {
        $this->assertPaneAdministrator($actor);
        $email = $this->normalizeEmail($email);

        $exists = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->exists();

        if ($exists) {
            throw new DomainException('Email already belongs to an active user.');
        }

        return DB::transaction(function () use ($actor, $email): array {
            UserInvitation::query()
                ->where('email', $email)
                ->where('status', UserInvitation::STATUS_PENDING)
                ->update([
                    'status' => UserInvitation::STATUS_REVOKED,
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            $token = Str::random(self::TOKEN_BYTES);
            $invitation = UserInvitation::query()->create([
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'status' => UserInvitation::STATUS_PENDING,
                'invited_by_user_id' => $actor->getKey(),
                'expires_at' => now()->addSeconds($this->expirySeconds()),
            ]);

            $this->audit->record('installation.user.invite', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'resource_ids' => ['user_invitation_id' => $invitation->getKey()],
                'metadata' => [
                    'email' => $email,
                    'expires_at' => $invitation->expires_at->toJSON(),
                ],
            ]);

            return ['invitation' => $invitation, 'token' => $token];
        });
    }

    public function revoke(User $actor, UserInvitation $invitation): UserInvitation
    {
        $this->assertPaneAdministrator($actor);

        return DB::transaction(function () use ($actor, $invitation): UserInvitation {
            $locked = UserInvitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isPending()) {
                return $locked;
            }

            $locked->forceFill([
                'status' => UserInvitation::STATUS_REVOKED,
                'revoked_at' => now(),
            ])->save();

            $this->audit->record('installation.user.invitation.revoke', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'resource_ids' => ['user_invitation_id' => $locked->getKey()],
                'metadata' => ['email' => $locked->email],
            ]);

            return $locked;
        });
    }

    public function exists(string $tokenHash): bool
    {
        return UserInvitation::query()->where('token_hash', $tokenHash)->exists();
    }

    /**
     * @param  array<string, mixed>  $workOsUser
     * @param  array<string, mixed>  $authentication
     */
    public function acceptHash(string $tokenHash, array $workOsUser, array $authentication = []): User
    {
        $email = $this->normalizeEmail((string) ($workOsUser['email'] ?? ''));

        if (($workOsUser['email_verified'] ?? false) !== true) {
            throw new InvalidArgumentException('User invitation requires a verified WorkOS email.');
        }

        if (! preg_match('/\A[a-f0-9]{64}\z/', $tokenHash)) {
            throw new InvalidArgumentException('User invitation is invalid.');
        }

        $result = DB::transaction(function () use ($tokenHash, $workOsUser, $authentication, $email): User|InvalidArgumentException {
            $invitation = UserInvitation::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof UserInvitation) {
                return new InvalidArgumentException('User invitation is invalid.');
            }

            if ($invitation->status === UserInvitation::STATUS_ACCEPTED) {
                return new InvalidArgumentException('User invitation was already accepted.');
            }

            if ($invitation->status === UserInvitation::STATUS_REVOKED) {
                return new InvalidArgumentException('User invitation was revoked.');
            }

            if ($invitation->status === UserInvitation::STATUS_EXPIRED || $invitation->isExpired()) {
                $invitation->forceFill(['status' => UserInvitation::STATUS_EXPIRED])->save();

                return new InvalidArgumentException('User invitation has expired.');
            }

            if (! hash_equals($invitation->email, $email)) {
                $this->audit->record('installation.user.invitation.accept', AuditEvent::OUTCOME_DENIED, [
                    'resource_ids' => ['user_invitation_id' => $invitation->getKey()],
                    'metadata' => [
                        'expected_email' => $invitation->email,
                        'actual_email' => $email,
                    ],
                ]);

                return new InvalidArgumentException('User invitation email does not match the WorkOS identity.');
            }

            $user = $this->syncInvitedUser($workOsUser, $authentication, $email);

            if (! $user instanceof User) {
                return new InvalidArgumentException('User invitation is invalid.');
            }

            $invitation->forceFill([
                'status' => UserInvitation::STATUS_ACCEPTED,
                'accepted_by_user_id' => $user->getKey(),
                'accepted_at' => now(),
            ])->save();

            $this->audit->record('installation.user.invitation.accept', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $user,
                'effective_user' => $user,
                'resource_ids' => [
                    'user_invitation_id' => $invitation->getKey(),
                    'user_id' => $user->getKey(),
                ],
                'changed_columns' => ['user_type_id', 'is_active'],
                'metadata' => ['email' => $email],
            ]);

            return $user;
        });

        if ($result instanceof InvalidArgumentException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Creates the standard user, or reactivates an existing standard user. An administrator is never
     * touched: a user invitation cannot change a role.
     *
     * @param  array<string, mixed>  $workOsUser
     * @param  array<string, mixed>  $authentication
     */
    private function syncInvitedUser(array $workOsUser, array $authentication, string $email): ?User
    {
        $user = User::query()
            ->where(function ($query) use ($workOsUser, $email): void {
                $query->where('email', $email);

                if (filled($workOsUser['id'] ?? null)) {
                    $query->orWhere('workos_id', $workOsUser['id']);
                }
            })
            ->lockForUpdate()
            ->first();

        if ($user instanceof User && (int) $user->user_type_id === User::PANE_ADMINISTRATOR_USER_TYPE_ID) {
            return null;
        }

        if (! $user instanceof User) {
            $user = new User([
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        $user->forceFill([
            'user_type_id' => User::STANDARD_USER_TYPE_ID,
            'name' => $this->displayName($workOsUser, $user->name ?: $email),
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

    private function expirySeconds(): int
    {
        // User invitations share the administrator invitation expiry setting.
        $seconds = $this->settings->resolve(SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS);

        if (! is_int($seconds)) {
            throw new DomainException('Invitation expiry setting must resolve to an integer.');
        }

        return $seconds;
    }

    private function assertPaneAdministrator(User $actor): void
    {
        if (! $actor->isPaneAdministrator()) {
            throw new DomainException('Only active Pane administrators can manage user invitations.');
        }
    }

    private function normalizeEmail(string $email): string
    {
        $normalized = strtolower(trim($email));

        if (! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('User invitation email must be valid.');
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $workOsUser
     */
    private function displayName(array $workOsUser, string $fallback): string
    {
        $name = trim(implode(' ', array_filter([
            $workOsUser['first_name'] ?? null,
            $workOsUser['last_name'] ?? null,
        ])));

        return $name !== '' ? $name : $fallback;
    }
}
