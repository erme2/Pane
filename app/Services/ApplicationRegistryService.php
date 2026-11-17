<?php

namespace App\Services;

use App\Models\ApplicationRegistration;
use App\Support\LatteApplicationConfig;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Resolves the trusted frontends from configuration only (FRONTEND_URL and PANE_EXTRA_FRONTEND_ORIGINS).
 * Ids are stable and derived from the origin; nothing is stored.
 */
class ApplicationRegistryService
{
    public function activeApplicationForOrigin(string $origin): ?ApplicationRegistration
    {
        $trustedOrigin = LatteApplicationConfig::normalizeOrigin($origin);

        if ($trustedOrigin === null) {
            return null;
        }

        $primary = $this->primaryApplication();

        if (hash_equals($primary->trusted_origin, $trustedOrigin)) {
            return $primary;
        }

        return array_key_exists($trustedOrigin, LatteApplicationConfig::extraFrontendOrigins())
            ? $this->extraApplication($trustedOrigin)
            : null;
    }

    public function activeApplicationForId(string $applicationId): ?ApplicationRegistration
    {
        if (! Str::isUuid($applicationId)) {
            return null;
        }

        foreach ($this->applications() as $application) {
            if (hash_equals($application->application_id, strtolower($applicationId))) {
                return $application;
            }
        }

        return null;
    }

    public function activeApplicationForSession(mixed $applicationId, mixed $sessionVersion): ?ApplicationRegistration
    {
        if (! is_string($applicationId) || ! is_string($sessionVersion) || ! Str::isUuid($sessionVersion)) {
            return null;
        }

        $application = $this->activeApplicationForId($applicationId);

        if (! $application instanceof ApplicationRegistration || ! hash_equals($application->session_version, strtolower($sessionVersion))) {
            return null;
        }

        return $application;
    }

    /**
     * Resolves the application for a request carrying a v1 session. Frontends share one session, so an
     * Origin from a sibling frontend selects that frontend; any other mismatched Origin is rejected.
     * Without an Origin the session application applies.
     */
    public function activeApplicationForSessionRequest(mixed $applicationId, mixed $sessionVersion, mixed $origin): ?ApplicationRegistration
    {
        $sessionApplication = $this->activeApplicationForSession($applicationId, $sessionVersion);

        if ($sessionApplication === null || $origin === null) {
            return $sessionApplication;
        }

        return is_string($origin) ? $this->activeApplicationForOrigin($origin) : null;
    }

    /**
     * @return array<int, ApplicationRegistration>
     */
    public function applications(): array
    {
        $applications = [$this->primaryApplication()];

        foreach (array_keys(LatteApplicationConfig::extraFrontendOrigins()) as $origin) {
            $applications[] = $this->extraApplication($origin);
        }

        return $applications;
    }

    public function configuredLatteApplication(): ApplicationRegistration
    {
        return $this->primaryApplication();
    }

    private function primaryApplication(): ApplicationRegistration
    {
        return new ApplicationRegistration(
            $this->configuredId('application_id', 'Latte application'),
            (string) config('app.name', 'Latte'),
            LatteApplicationConfig::trustedOrigin(),
            LatteApplicationConfig::redirectUris(),
        );
    }

    private function extraApplication(string $trustedOrigin): ApplicationRegistration
    {
        return new ApplicationRegistration(
            Uuid::uuid5($this->configuredId('application_id', 'Latte application'), $trustedOrigin)->toString(),
            $trustedOrigin,
            $trustedOrigin,
            LatteApplicationConfig::extraFrontendOrigins()[$trustedOrigin],
        );
    }

    private function configuredId(string $key, string $label): string
    {
        $id = (string) config('services.latte.'.$key);

        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException("Configured {$label} ID must be a UUID.");
        }

        return strtolower($id);
    }
}
