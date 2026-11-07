<?php

namespace App\Services;

use App\Models\ApplicationRegistration;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\LatteApplicationConfig;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

class ApplicationRegistryService
{
    private const CONFIGURED_LATTE_SOURCE = 'configured_latte';

    private const CONFIGURED_FRONTEND_SOURCE = 'configured_frontend';

    public function __construct(private readonly AuditEventService $audit) {}

    public function activeApplicationForOrigin(string $origin): ?ApplicationRegistration
    {
        $trustedOrigin = LatteApplicationConfig::normalizeOrigin($origin);

        if ($trustedOrigin === null) {
            return null;
        }

        $application = ApplicationRegistration::query()
            ->where('active_trusted_origin', $trustedOrigin)
            ->where('status', ApplicationRegistration::STATUS_ACTIVE)
            ->first();

        if ($application instanceof ApplicationRegistration) {
            return $this->isConfiguredFrontend($application)
                ? $this->syncConfiguredFrontend($application)
                : $application;
        }

        $configured = $this->configuredLatteApplication();

        if ($configured->isActive() && hash_equals($configured->trusted_origin, $trustedOrigin)) {
            return $configured;
        }

        return array_key_exists($trustedOrigin, LatteApplicationConfig::additionalFrontends())
            ? $this->configuredFrontendApplication($trustedOrigin)
            : null;
    }

    public function activeApplicationForId(string $applicationId): ?ApplicationRegistration
    {
        if (! Str::isUuid($applicationId)) {
            return null;
        }

        $application = ApplicationRegistration::query()->find($applicationId);

        try {
            $configuredApplicationId = $this->configuredLatteApplicationId();
        } catch (InvalidArgumentException) {
            return $application instanceof ApplicationRegistration && $application->isActive()
                ? $application
                : null;
        }

        if (hash_equals($configuredApplicationId, $applicationId)) {
            $application = $this->configuredLatteApplication();

            return $application->isActive() ? $application : null;
        }

        if ($application instanceof ApplicationRegistration && $this->isConfiguredFrontend($application)) {
            return $this->syncConfiguredFrontend($application);
        }

        return $application instanceof ApplicationRegistration && $application->isActive()
            ? $application
            : null;
    }

    public function activeApplicationForSession(mixed $applicationId, mixed $sessionVersion): ?ApplicationRegistration
    {
        if (! is_string($applicationId) || ! is_string($sessionVersion) || ! Str::isUuid($sessionVersion)) {
            return null;
        }

        $application = $this->activeApplicationForId($applicationId);

        if (! $application instanceof ApplicationRegistration || ! hash_equals((string) $application->session_version, $sessionVersion)) {
            return null;
        }

        return $application;
    }

    /**
     * Resolves the application for a request carrying a v1 session. Latte frontends of the same
     * organization share one session, so an Origin from a sibling frontend selects that frontend;
     * any other mismatched Origin is rejected. Without an Origin the session application applies.
     */
    public function activeApplicationForSessionRequest(mixed $applicationId, mixed $sessionVersion, mixed $origin): ?ApplicationRegistration
    {
        $sessionApplication = $this->activeApplicationForSession($applicationId, $sessionVersion);

        if ($sessionApplication === null || $origin === null) {
            return $sessionApplication;
        }

        $originApplication = is_string($origin) ? $this->activeApplicationForOrigin($origin) : null;

        if ($originApplication === null) {
            return null;
        }

        if (hash_equals((string) $sessionApplication->getKey(), (string) $originApplication->getKey())) {
            return $sessionApplication;
        }

        return $sessionApplication->isLatte()
            && $originApplication->isLatte()
            && $sessionApplication->organization_id !== null
            && hash_equals((string) $sessionApplication->organization_id, (string) $originApplication->organization_id)
                ? $originApplication
                : null;
    }

    public function configuredLatteApplication(): ApplicationRegistration
    {
        $applicationId = $this->configuredLatteApplicationId();
        $organization = $this->configuredLatteOrganization();
        $trustedOrigin = LatteApplicationConfig::trustedOrigin();
        $redirectUris = LatteApplicationConfig::redirectUris();

        return DB::transaction(function () use ($applicationId, $organization, $trustedOrigin, $redirectUris): ApplicationRegistration {
            $application = ApplicationRegistration::query()
                ->whereKey($applicationId)
                ->lockForUpdate()
                ->first();

            if ($application instanceof ApplicationRegistration) {
                if ($application->isActive()) {
                    $application->forceFill([
                        'name' => (string) config('app.name', 'Latte'),
                        'kind' => ApplicationRegistration::KIND_LATTE,
                        'organization_id' => $organization->getKey(),
                        'trusted_origin' => $trustedOrigin,
                        'redirect_uris' => $redirectUris,
                        'details' => array_replace($application->details ?? [], [
                            'source' => self::CONFIGURED_LATTE_SOURCE,
                        ]),
                    ])->save();
                }

                return $application;
            }

            $conflictingOriginApplication = ApplicationRegistration::query()
                ->where('active_trusted_origin', $trustedOrigin)
                ->where('status', ApplicationRegistration::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($this->isConfiguredLatteApplication($conflictingOriginApplication)) {
                $conflictingOriginApplication->forceFill([
                    'application_id' => $applicationId,
                    'name' => (string) config('app.name', 'Latte'),
                    'kind' => ApplicationRegistration::KIND_LATTE,
                    'organization_id' => $organization->getKey(),
                    'trusted_origin' => $trustedOrigin,
                    'redirect_uris' => $redirectUris,
                    'details' => array_replace($conflictingOriginApplication->details ?? [], [
                        'source' => self::CONFIGURED_LATTE_SOURCE,
                    ]),
                ])->save();

                return $conflictingOriginApplication;
            }

            return ApplicationRegistration::query()->create([
                'application_id' => $applicationId,
                'name' => (string) config('app.name', 'Latte'),
                'kind' => ApplicationRegistration::KIND_LATTE,
                'organization_id' => $organization->getKey(),
                'trusted_origin' => $trustedOrigin,
                'redirect_uris' => $redirectUris,
                'status' => ApplicationRegistration::STATUS_ACTIVE,
                'details' => ['source' => self::CONFIGURED_LATTE_SOURCE],
            ]);
        });
    }

    /**
     * @param  array{name: string, kind: string, trusted_origin: string, redirect_uris: array<int, string>, organization_id?: string|null}  $attributes
     */
    public function create(User $actor, array $attributes): ApplicationRegistration
    {
        $this->assertPaneAdministrator($actor);

        return DB::transaction(function () use ($actor, $attributes): ApplicationRegistration {
            $this->assertActiveOriginAvailable($attributes['trusted_origin']);

            try {
                $application = ApplicationRegistration::query()->create([
                    'name' => $attributes['name'],
                    'kind' => $attributes['kind'],
                    'organization_id' => $attributes['kind'] === ApplicationRegistration::KIND_LATTE
                        ? $attributes['organization_id']
                        : null,
                    'trusted_origin' => $attributes['trusted_origin'],
                    'redirect_uris' => $attributes['redirect_uris'],
                    'status' => ApplicationRegistration::STATUS_ACTIVE,
                ]);
            } catch (QueryException $exception) {
                $this->throwDuplicateOriginWhenUniqueConstraintFails($exception);

                throw $exception;
            }

            $this->audit->record('application.create', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'organization' => $application->organization,
                'resource_ids' => ['application_id' => $application->getKey()],
                'metadata' => [
                    'kind' => $application->kind,
                    'trusted_origin' => $application->trusted_origin,
                ],
            ]);

            return $application;
        });
    }

    /**
     * @param  array{name?: string, trusted_origin?: string, redirect_uris?: array<int, string>, status?: string}  $attributes
     */
    public function update(User $actor, ApplicationRegistration $application, array $attributes): ApplicationRegistration
    {
        $this->assertPaneAdministrator($actor);

        return DB::transaction(function () use ($actor, $application, $attributes): ApplicationRegistration {
            $locked = ApplicationRegistration::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $targetStatus = $attributes['status'] ?? $locked->status;
            $targetOrigin = $attributes['trusted_origin'] ?? $locked->trusted_origin;

            if ($targetStatus === ApplicationRegistration::STATUS_ACTIVE) {
                $this->assertActiveOriginAvailable($targetOrigin, $locked);
            }

            try {
                $shouldInvalidateSessions = $locked->isActive()
                    && $targetStatus === ApplicationRegistration::STATUS_DISABLED;

                $locked->forceFill($attributes);

                if ($shouldInvalidateSessions) {
                    $locked->rotateSessionVersion();
                }

                $locked->save();
            } catch (QueryException $exception) {
                $this->throwDuplicateOriginWhenUniqueConstraintFails($exception);

                throw $exception;
            }

            $this->audit->record('application.update', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'organization' => $locked->organization,
                'resource_ids' => ['application_id' => $locked->getKey()],
                'metadata' => [
                    'status' => $locked->status,
                    'trusted_origin' => $locked->trusted_origin,
                ],
            ]);

            return $locked;
        });
    }

    public function disable(User $actor, ApplicationRegistration $application): ApplicationRegistration
    {
        return $this->update($actor, $application, ['status' => ApplicationRegistration::STATUS_DISABLED]);
    }

    public function fixedOrganizationFor(ApplicationRegistration $application): ?Organization
    {
        if (! $application->isLatte() || $application->organization_id === null) {
            return null;
        }

        $organization = $application->organization()->first();

        return $organization instanceof Organization ? $organization : null;
    }

    private function configuredLatteOrganization(): Organization
    {
        $organizationId = (string) config('services.latte.organization_id');

        if (! Str::isUuid($organizationId)) {
            throw new InvalidArgumentException('Configured Latte organization ID must be a UUID.');
        }

        return DB::transaction(function () use ($organizationId): Organization {
            $organization = Organization::query()
                ->whereKey($organizationId)
                ->lockForUpdate()
                ->first();

            if ($organization instanceof Organization) {
                return $organization;
            }

            return Organization::query()->create([
                'organization_id' => $organizationId,
                'name' => 'Latte Local',
                'slug' => $this->availableConfiguredSlug('latte-local'),
                'status' => Organization::STATUS_ACTIVE,
                'database_limit' => 1,
            ]);
        });
    }

    private function configuredLatteApplicationId(): string
    {
        $applicationId = (string) config('services.latte.application_id');

        if (! Str::isUuid($applicationId)) {
            throw new InvalidArgumentException('Configured Latte application ID must be a UUID.');
        }

        return $applicationId;
    }

    private function availableConfiguredSlug(string $slug): string
    {
        if (! Organization::query()->where('slug', $slug)->exists()) {
            return $slug;
        }

        $suffix = 1;

        do {
            $candidate = $slug.'-'.substr((string) Str::uuid(), 0, 8).'-'.$suffix;
            $suffix++;
        } while (Organization::query()->where('slug', $candidate)->exists());

        return $candidate;
    }

    private function configuredFrontendApplication(string $trustedOrigin): ?ApplicationRegistration
    {
        // Derived from the origin so the ID, and sessions bound to it, survive redeploys.
        $applicationId = Uuid::uuid5($this->configuredLatteApplicationId(), $trustedOrigin)->toString();
        $organization = $this->configuredLatteOrganization();

        return DB::transaction(function () use ($applicationId, $organization, $trustedOrigin): ?ApplicationRegistration {
            $application = ApplicationRegistration::query()
                ->whereKey($applicationId)
                ->lockForUpdate()
                ->first();

            if ($application instanceof ApplicationRegistration) {
                return $this->syncConfiguredFrontend($application);
            }

            return ApplicationRegistration::query()->create([
                'application_id' => $applicationId,
                'name' => $trustedOrigin,
                'kind' => ApplicationRegistration::KIND_LATTE,
                'organization_id' => $organization->getKey(),
                'trusted_origin' => $trustedOrigin,
                'redirect_uris' => LatteApplicationConfig::additionalFrontends()[$trustedOrigin],
                'status' => ApplicationRegistration::STATUS_ACTIVE,
                'details' => ['source' => self::CONFIGURED_FRONTEND_SOURCE],
            ]);
        });
    }

    /**
     * Keeps an env-configured frontend in line with LATTE_ADDITIONAL_FRONTENDS: removing it from the
     * env disables it (and its sessions), adding it back re-enables it. A frontend disabled through
     * the API stays disabled.
     */
    private function syncConfiguredFrontend(ApplicationRegistration $application): ?ApplicationRegistration
    {
        $redirectUris = LatteApplicationConfig::additionalFrontends()[$application->trusted_origin] ?? null;
        $details = $application->details ?? [];
        $disabledByConfiguration = ($details['disabled_by'] ?? null) === 'configuration';

        if ($redirectUris === null) {
            if ($application->isActive()) {
                $application->forceFill([
                    'status' => ApplicationRegistration::STATUS_DISABLED,
                    'details' => array_replace($details, ['disabled_by' => 'configuration']),
                ]);
                $application->rotateSessionVersion();
                $application->save();
            }

            return null;
        }

        if (! $application->isActive() && ! $disabledByConfiguration) {
            return null;
        }

        if ($disabledByConfiguration) {
            unset($details['disabled_by']);
        }

        $application->forceFill([
            'status' => ApplicationRegistration::STATUS_ACTIVE,
            'redirect_uris' => $redirectUris,
            'details' => $details,
        ]);

        if ($application->isDirty()) {
            $application->save();
        }

        return $application;
    }

    private function isConfiguredFrontend(ApplicationRegistration $application): bool
    {
        return ($application->details['source'] ?? null) === self::CONFIGURED_FRONTEND_SOURCE;
    }

    private function assertPaneAdministrator(User $actor): void
    {
        if (! $actor->isPaneAdministrator()) {
            throw new DomainException('Only active Pane administrators can manage applications.');
        }
    }

    private function isConfiguredLatteApplication(mixed $application): bool
    {
        return $application instanceof ApplicationRegistration
            && ($application->details['source'] ?? null) === self::CONFIGURED_LATTE_SOURCE;
    }

    private function assertActiveOriginAvailable(string $trustedOrigin, ?ApplicationRegistration $except = null): void
    {
        $duplicate = ApplicationRegistration::query()
            ->where('active_trusted_origin', $trustedOrigin)
            ->where('status', ApplicationRegistration::STATUS_ACTIVE)
            ->when($except instanceof ApplicationRegistration, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->lockForUpdate()
            ->exists();

        if ($duplicate) {
            throw new DomainException('Application trusted origin is already registered.');
        }
    }

    private function throwDuplicateOriginWhenUniqueConstraintFails(QueryException $exception): void
    {
        if (
            in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && str_contains($exception->getMessage(), 'active_trusted_origin')
        ) {
            throw new DomainException('Application trusted origin is already registered.', previous: $exception);
        }
    }
}
