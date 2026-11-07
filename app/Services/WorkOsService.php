<?php

namespace App\Services;

use App\Models\ApplicationRegistration;
use App\Support\LatteApplicationConfig;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class WorkOsService
{
    private const API_BASE_URL = 'https://api.workos.com';

    public function authorizationUrl(string $state, ?ApplicationRegistration $application = null): string
    {
        $query = [
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri($application),
            'state' => $state,
        ];

        $provider = config('services.workos.provider', 'authkit');

        if (filled($provider)) {
            $query['provider'] = $provider;
        } elseif (filled(config('services.workos.organization_id'))) {
            $query['organization_id'] = config('services.workos.organization_id');
        } elseif (filled(config('services.workos.connection_id'))) {
            $query['connection_id'] = config('services.workos.connection_id');
        }

        return self::API_BASE_URL.'/user_management/authorize?'.http_build_query($query);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function authenticateWithCode(string $code, ?string $ipAddress, ?string $userAgent): array
    {
        return Http::acceptJson()
            ->asJson()
            ->post(self::API_BASE_URL.'/user_management/authenticate', array_filter([
                'client_id' => $this->clientId(),
                'client_secret' => $this->apiKey(),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ], fn ($value) => filled($value)))
            ->throw()
            ->json();
    }

    public function logoutUrl(string $sessionId, ?ApplicationRegistration $application = null): string
    {
        $returnTo = $this->logoutReturnTo($application);

        $query = array_filter([
            'session_id' => $sessionId,
            'return_to' => $returnTo,
        ], fn ($value) => filled($value));

        return self::API_BASE_URL.'/user_management/sessions/logout?'.http_build_query($query);
    }

    public function logoutReturnTo(?ApplicationRegistration $application = null): string
    {
        if ($this->isAdditionalApplication($application)) {
            return $application->trusted_origin.'/';
        }

        $configured = config('services.workos.return_to');

        if (is_string($configured) && filled($configured)) {
            $normalized = LatteApplicationConfig::normalizeRedirectUri($configured);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return LatteApplicationConfig::trustedOrigin().'/';
    }

    public function configured(): bool
    {
        return filled(config('services.workos.api_key'))
            && filled(config('services.workos.client_id'))
            && filled(config('services.workos.redirect_uri'));
    }

    public function ensureConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('WorkOS is not configured. Set WORKOS_API_KEY, WORKOS_CLIENT_ID, and WORKOS_REDIRECT_URI.');
        }
    }

    public function makeState(): string
    {
        return Str::random(40);
    }

    private function apiKey(): string
    {
        $this->ensureConfigured();

        return config('services.workos.api_key');
    }

    private function clientId(): string
    {
        $this->ensureConfigured();

        return config('services.workos.client_id');
    }

    /**
     * WORKOS_REDIRECT_URI belongs to the FRONTEND_URL application; every other application gets the
     * same callback path on its own origin, which must also be listed in the WorkOS Redirects.
     */
    private function redirectUri(?ApplicationRegistration $application): string
    {
        $this->ensureConfigured();

        $configured = (string) config('services.workos.redirect_uri');

        if (! $this->isAdditionalApplication($application)) {
            return $configured;
        }

        $path = (string) (parse_url($configured, PHP_URL_PATH) ?: '/');
        $query = parse_url($configured, PHP_URL_QUERY);

        return $application->trusted_origin.$path.(is_string($query) ? '?'.$query : '');
    }

    /**
     * @phpstan-assert-if-true ApplicationRegistration $application
     */
    private function isAdditionalApplication(?ApplicationRegistration $application): bool
    {
        return $application instanceof ApplicationRegistration
            && ! hash_equals((string) config('services.latte.application_id'), (string) $application->getKey());
    }
}
