<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-device bearer tokens for native clients (Pane#130). A device holds a short-lived access
 * token and a rotating refresh token. Only hashes are stored. Presenting a refresh token that was
 * already replaced means a copy leaked, so the whole device is signed out.
 */
class DeviceTokenService
{
    public const string ACCESS_PREFIX = 'pane_at_';

    public const string REFRESH_PREFIX = 'pane_rt_';

    private const string REQUEST_ATTRIBUTE = 'pane_device_token';

    public function __construct(private readonly AuditEventService $audit) {}

    /**
     * Signs a member in on a new device.
     *
     * @return array{device: DeviceToken, access_token: string, refresh_token: string}
     */
    public function issue(User $user, ?string $deviceLabel, ?string $workOsSessionId): array
    {
        return DB::transaction(function () use ($user, $deviceLabel, $workOsSessionId): array {
            $this->makeRoom($user);

            $device = new DeviceToken([
                'user_id' => $user->getKey(),
                'device_label' => $deviceLabel,
                'workos_session_id' => $workOsSessionId,
                'last_used_at' => now(),
            ]);

            return $this->rotate($device);
        });
    }

    /**
     * Swaps a refresh token for a new access and refresh token. Null when the refresh token is
     * unknown, expired, already used, or its member can no longer sign in.
     *
     * @return array{device: DeviceToken, access_token: string, refresh_token: string}|null
     */
    public function refresh(string $refreshToken): ?array
    {
        if (! $this->looksLike($refreshToken, self::REFRESH_PREFIX)) {
            return null;
        }

        $hash = DeviceToken::hash($refreshToken);

        return DB::transaction(function () use ($hash): ?array {
            $device = DeviceToken::query()->where('refresh_token_hash', $hash)->lockForUpdate()->first();

            if (! $device instanceof DeviceToken) {
                $this->revokeReused($hash);

                return null;
            }

            $user = User::query()->find($device->user_id);

            if ($device->refresh_expires_at->isPast() || ! $user instanceof User || ! (bool) $user->is_active) {
                $device->delete();

                return null;
            }

            $device->last_used_at = now();

            return $this->rotate($device);
        });
    }

    /** The member behind a request's bearer token, or null (also for an expired token or an inactive member). */
    public function userForRequest(Request $request): ?User
    {
        $device = $this->deviceForRequest($request);

        if (! $device instanceof DeviceToken) {
            return null;
        }

        $user = User::query()->find($device->user_id);

        return $user instanceof User && (bool) $user->is_active ? $user : null;
    }

    /** The device whose valid access token the request carries. */
    public function deviceForRequest(Request $request): ?DeviceToken
    {
        if ($request->attributes->has(self::REQUEST_ATTRIBUTE)) {
            $cached = $request->attributes->get(self::REQUEST_ATTRIBUTE);

            return $cached instanceof DeviceToken ? $cached : null;
        }

        $token = $request->bearerToken();
        $device = null;

        if (is_string($token) && $this->looksLike($token, self::ACCESS_PREFIX)) {
            $device = DeviceToken::query()->where('access_token_hash', DeviceToken::hash($token))->first();

            if ($device instanceof DeviceToken && $device->access_expires_at->isPast()) {
                $device = null;
            }
        }

        if ($device instanceof DeviceToken && ($device->last_used_at === null || $device->last_used_at->diffInSeconds(now(), true) >= 60)) {
            DeviceToken::query()->whereKey($device->getKey())->update(['last_used_at' => now()]);
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $device);

        return $device;
    }

    /** Whether the request carries a valid device access token (it then needs no CSRF token or Origin). */
    public function isDeviceRequest(Request $request): bool
    {
        return $this->userForRequest($request) instanceof User;
    }

    public function revoke(DeviceToken $device): void
    {
        $device->delete();
    }

    /** Signs a member out of every device. */
    public function revokeAllFor(User $user): void
    {
        DeviceToken::query()->where('user_id', $user->getKey())->delete();
    }

    /**
     * @return array{device: DeviceToken, access_token: string, refresh_token: string}
     */
    private function rotate(DeviceToken $device): array
    {
        $access = self::ACCESS_PREFIX.Str::random(48);
        $refresh = self::REFRESH_PREFIX.Str::random(64);

        $device->previous_refresh_hash = $device->exists ? $device->refresh_token_hash : null;
        $device->access_token_hash = DeviceToken::hash($access);
        $device->access_expires_at = now()->addSeconds($this->accessTtl());
        $device->refresh_token_hash = DeviceToken::hash($refresh);
        $device->refresh_expires_at = now()->addSeconds($this->refreshTtl());
        $device->save();

        return ['device' => $device, 'access_token' => $access, 'refresh_token' => $refresh];
    }

    /** Seconds a new access token lives. */
    public function accessTtl(): int
    {
        return max(60, (int) config('pane.native_auth.access_ttl'));
    }

    /** Seconds a new refresh token lives. */
    public function refreshTtl(): int
    {
        return max(60, (int) config('pane.native_auth.refresh_ttl'));
    }

    /** Keeps a member to the most devices allowed: the least recently used one is signed out. */
    private function makeRoom(User $user): void
    {
        $max = max(1, (int) config('pane.native_auth.max_devices_per_user'));
        $ids = DeviceToken::query()
            ->where('user_id', $user->getKey())
            ->orderByRaw('last_used_at is null desc, last_used_at asc')
            ->pluck('device_id');

        $excess = $ids->count() - $max + 1;

        if ($excess > 0) {
            DeviceToken::query()->whereIn('device_id', $ids->take($excess)->all())->delete();
        }
    }

    private function revokeReused(string $hash): void
    {
        $device = DeviceToken::query()->where('previous_refresh_hash', $hash)->lockForUpdate()->first();

        if (! $device instanceof DeviceToken) {
            return;
        }

        $user = User::query()->find($device->user_id);
        $device->delete();

        if ($user instanceof User) {
            $this->audit->record('auth.native.refresh_reuse', AuditEvent::OUTCOME_DENIED, [
                'effective_user' => $user,
                'resource_ids' => ['device_id' => $device->device_id],
            ]);
        }
    }

    private function looksLike(string $token, string $prefix): bool
    {
        return str_starts_with($token, $prefix) && strlen($token) <= 255;
    }
}
