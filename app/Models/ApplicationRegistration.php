<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;

/**
 * A frontend this Pane trusts, built in memory from FRONTEND_URL + PANE_EXTRA_FRONTEND_ORIGINS.
 * It has no table: removing a frontend from the env stops it resolving. The session version includes
 * PANE_SESSION_EPOCH, so changing the epoch revokes every existing session binding (bump it when re-adding a frontend).
 */
final class ApplicationRegistration
{
    public const string KIND_LATTE = 'latte';

    public const string STATUS_ACTIVE = 'active';

    public readonly string $session_version;

    public readonly string $kind;

    public readonly string $status;

    /**
     * @param  array<int, string>  $redirect_uris
     */
    public function __construct(
        public readonly string $application_id,
        public readonly ?string $organization_id,
        public readonly string $name,
        public readonly string $trusted_origin,
        public readonly array $redirect_uris,
    ) {
        $this->session_version = Uuid::uuid5($application_id, 'session:'.(string) config('services.latte.session_epoch', ''))->toString();
        $this->kind = self::KIND_LATTE;
        $this->status = self::STATUS_ACTIVE;
    }

    public function getKey(): string
    {
        return $this->application_id;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function isLatte(): bool
    {
        return true;
    }
}
