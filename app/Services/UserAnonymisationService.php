<?php

namespace App\Services;

use App\Exceptions\PersonalDataCleanupException;
use App\Exceptions\UserAnonymisationException;
use App\Models\AuditEvent;
use App\Models\DeviceToken;
use App\Models\PaneAdminInvitation;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserInvitation;
use DomainException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Anonymises a user for personal-data deletion (UK GDPR erasure, store account-deletion rules).
 *
 * The user row stays, so ownership of CRUD rows and audit actor ids keep working, but it no
 * longer holds a name, an email or a WorkOS link and can't sign in. Nothing maps the old
 * identity to the new one: the same email invited later is a brand-new user.
 */
class UserAnonymisationService
{
    private const string REDACTED = '[redacted]';

    private const string ANONYMISED_DOMAIN = 'anonymised.invalid';

    private const int AUDIT_CHUNK_SIZE = 200;

    public function __construct(
        private readonly AuditEventService $audit,
        private readonly WorkOsService $workOs,
        private readonly PersonalDataCleaner $personalData,
    ) {}

    /**
     * Idempotent: an already anonymised user returns as it is.
     *
     * @throws DomainException when the actor may not do this, or it would remove the last active administrator
     * @throws UserAnonymisationException when WorkOS could not delete the user; nothing changed in Pane, so retry
     */
    public function anonymise(User $actor, User $target): User
    {
        $this->assertMayAnonymise($actor, $target);

        $current = User::query()->findOrFail($target->getKey());

        if ($this->isAnonymised($current)) {
            // Anonymising again is how a cleanup that failed the first time gets retried.
            $this->cleanPersonalData($actor, $current);

            return $current;
        }

        $anonymised = DB::transaction(function () use ($actor, $current): User {
            // Lock every active administrator row first, so two overlapping erasures take turns
            // and the last-administrator check below sees the other one's result. The lock is held
            // across the WorkOS call on purpose: the check must cover the identity deletion.
            $this->lockActiveAdministrators();

            $locked = User::query()->whereKey($current->getKey())->lockForUpdate()->firstOrFail();

            if ($this->isAnonymised($locked)) {
                return $locked;
            }

            $this->assertNotLastActiveAdministrator($locked);

            // WorkOS goes first and is safe to repeat: if the database step then fails, the stored
            // WorkOS id is still there and a retry finds the WorkOS user already gone.
            $this->deleteWorkOsUser($locked);

            $email = strtolower(trim((string) $locked->email));

            $this->redactInvitations($locked, $email);
            $this->redactAuditMetadata($email);
            // A removed user's devices must never get another push.
            PushSubscription::query()->where('user_id', $locked->getKey())->delete();
            // ... and no signed-in native device may keep working.
            DeviceToken::query()->where('user_id', $locked->getKey())->delete();
            $this->blankUser($locked);

            // No email, name or WorkOS id here: only the anonymised row's own id.
            $this->audit->record('user.anonymise', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor->is($locked) ? $locked : $actor,
                'effective_user' => $locked,
                'resource_ids' => ['user_id' => $locked->getKey()],
                'changed_columns' => ['name', 'email', 'workos_id', 'details', 'is_active'],
            ]);

            return $locked;
        });

        $this->cleanPersonalData($actor, $anonymised);

        return $anonymised;
    }

    public function isAnonymised(User $user): bool
    {
        return filled(data_get($user->details, 'anonymised_at'));
    }

    /**
     * The install's own data (sweegly#49). The user is anonymised for good by now, so a failure
     * here must not undo that or tell the user to retry: it is logged, audited, and repeated by
     * `php artisan personal-data:clean <uuid>` or by anonymising the user again.
     */
    private function cleanPersonalData(User $actor, User $user): void
    {
        $outcome = AuditEvent::OUTCOME_SUCCESS;
        try {
            $this->personalData->clean($user);
        } catch (PersonalDataCleanupException $exception) {
            $outcome = AuditEvent::OUTCOME_FAILURE;
            report($exception);
        }

        $this->audit->record('user.personal_data_cleanup', $outcome, [
            'real_actor' => $actor->is($user) ? $user : $actor,
            'effective_user' => $user,
            'resource_ids' => ['user_id' => $user->getKey()],
        ]);
    }

    private function assertMayAnonymise(User $actor, User $target): void
    {
        if ($actor->is($target) || $actor->isPaneAdministrator()) {
            return;
        }

        throw new DomainException('Only the user or an active Pane administrator can anonymise a user.');
    }

    private function lockActiveAdministrators(): void
    {
        User::query()
            ->where('user_type_id', User::PANE_ADMINISTRATOR_USER_TYPE_ID)
            ->where('is_active', true)
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get(['user_id']);
    }

    private function assertNotLastActiveAdministrator(User $user): void
    {
        if (! $user->isPaneAdministrator()) {
            return;
        }

        $others = User::query()
            ->where('user_type_id', User::PANE_ADMINISTRATOR_USER_TYPE_ID)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->count();

        if ($others === 0) {
            throw new DomainException('Cannot anonymise the final active Pane administrator.');
        }
    }

    private function deleteWorkOsUser(User $user): void
    {
        if (! filled($user->workos_id)) {
            return;
        }

        try {
            $this->workOs->deleteUser((string) $user->workos_id);
        } catch (HttpClientException) {
            // Never carry the provider's message: it can echo the WorkOS user id.
            throw new UserAnonymisationException('WorkOS could not delete the user. Nothing was changed; try again.');
        }
    }

    private function blankUser(User $user): void
    {
        $suffix = Str::lower(Str::random(16));

        $user->forceFill([
            'name' => 'anon-'.$suffix,
            'email' => 'anon-'.$suffix.'@'.self::ANONYMISED_DOMAIN,
            'email_verified_at' => null,
            'password' => Hash::make(Str::random(40)),
            'workos_id' => null,
            'workos_organization_id' => null,
            'details' => ['anonymised_at' => now()->toJSON()],
            'settings' => null,
            'is_active' => false,
            'last_login_at' => null,
            'terms_version' => null,
            'terms_accepted_at' => null,
        ])->save();
    }

    private function redactInvitations(User $user, string $email): void
    {
        UserInvitation::query()
            ->where(function ($query) use ($user, $email): void {
                $query->whereRaw('LOWER(email) = ?', [$email])
                    ->orWhere('accepted_by_user_id', $user->getKey());
            })
            ->each(function (UserInvitation $invitation): void {
                $invitation->forceFill([
                    'email' => 'anon-'.Str::lower(Str::random(16)).'@'.self::ANONYMISED_DOMAIN,
                    'status' => $invitation->isPending() ? UserInvitation::STATUS_REVOKED : $invitation->status,
                    'revoked_at' => $invitation->isPending() ? now() : $invitation->revoked_at,
                ])->save();
            });

        PaneAdminInvitation::query()
            ->where(function ($query) use ($user, $email): void {
                $query->whereRaw('LOWER(email) = ?', [$email])
                    ->orWhere('accepted_by_user_id', $user->getKey());
            })
            ->each(function (PaneAdminInvitation $invitation): void {
                $invitation->forceFill([
                    'email' => 'anon-'.Str::lower(Str::random(16)).'@'.self::ANONYMISED_DOMAIN,
                    'status' => $invitation->isPending() ? PaneAdminInvitation::STATUS_REVOKED : $invitation->status,
                    'revoked_at' => $invitation->isPending() ? now() : $invitation->revoked_at,
                ])->save();
            });
    }

    /**
     * Audit rows are append-only except for `metadata`, which is where emails were recorded.
     */
    private function redactAuditMetadata(string $email): void
    {
        if ($email === '') {
            return;
        }

        $table = (new AuditEvent)->getTable();

        DB::table($table)
            ->whereNotNull('metadata')
            ->orderBy('audit_event_id')
            ->chunk(self::AUDIT_CHUNK_SIZE, function ($events) use ($table, $email): void {
                foreach ($events as $event) {
                    $metadata = json_decode((string) $event->metadata, true);

                    if (! is_array($metadata)) {
                        continue;
                    }

                    $redacted = $this->redactEmail($metadata, $email, $changed);

                    if ($changed) {
                        DB::table($table)
                            ->where('audit_event_id', $event->audit_event_id)
                            ->update(['metadata' => json_encode($redacted, JSON_THROW_ON_ERROR)]);
                    }
                }
            });
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redactEmail(array $data, string $email, ?bool &$changed): array
    {
        $changed ??= false;

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redactEmail($value, $email, $changed);
            } elseif (is_string($value) && stripos($value, $email) !== false) {
                $data[$key] = str_ireplace($email, self::REDACTED, $value);
                $changed = true;
            }
        }

        return $data;
    }
}
