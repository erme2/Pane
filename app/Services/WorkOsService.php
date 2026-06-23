<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class WorkOsService
{
    private const API_BASE_URL = 'https://api.workos.com';

    public function authorizationUrl(string $state): string
    {
        $query = array_filter([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'provider' => config('services.workos.provider', 'authkit'),
            'organization_id' => config('services.workos.organization_id'),
            'connection_id' => config('services.workos.connection_id'),
        ], fn ($value) => filled($value));

        return self::API_BASE_URL . '/user_management/authorize?' . http_build_query($query);
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
            ->post(self::API_BASE_URL . '/user_management/authenticate', array_filter([
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

    public function logoutUrl(?string $sessionId = null): string
    {
        $returnTo = config('services.workos.return_to') ?: url('/');

        if (! filled($sessionId)) {
            return $returnTo;
        }

        $query = array_filter([
            'session_id' => $sessionId,
            'return_to' => $returnTo,
        ], fn ($value) => filled($value));

        return self::API_BASE_URL . '/user_management/sessions/logout?' . http_build_query($query);
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

    private function redirectUri(): string
    {
        $this->ensureConfigured();

        return config('services.workos.redirect_uri');
    }
}
