<?php

namespace App\Support;

class LatteApplicationConfig
{
    public static function trustedOrigin(): string
    {
        return self::trustedOriginFromUrl((string) config('services.latte.frontend_url'))
            ?? 'https://latte.localhost';
    }

    public static function trustedOriginFromUrl(string $url): ?string
    {
        return self::originFromUrl($url, true);
    }

    /**
     * @return array<int, string>
     */
    public static function redirectUris(): array
    {
        $redirectUris = [];

        foreach (self::configuredRedirectUris() as $redirectUri) {
            $normalized = self::normalizeRedirectUri($redirectUri);

            if ($normalized !== null) {
                $redirectUris[] = $normalized;
            }
        }

        if ($redirectUris === []) {
            $redirectUris[] = self::trustedOrigin().'/auth/callback';
        }

        return array_values(array_unique($redirectUris));
    }

    public static function isAllowedOrigin(string $origin): bool
    {
        return self::normalizeOrigin($origin) === self::trustedOrigin();
    }

    public static function normalizeOrigin(string $origin): ?string
    {
        return self::originFromUrl($origin, false);
    }

    public static function normalizeRedirectUri(string $url): ?string
    {
        $url = trim($url);

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = self::normalizeHost((string) $parts['host']);

        if (! self::schemeAllowsHost($scheme, $host)) {
            return null;
        }

        $port = self::originPort($parts);
        $defaultPort = self::originPort(['scheme' => $scheme]);
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?'.(string) $parts['query'] : '';

        if ($path === '') {
            $path = '/';
        }

        return $scheme.'://'.$host.($port !== null && $port !== $defaultPort ? ':'.$port : '').$path.$query;
    }

    /**
     * @return array<int, string>
     */
    private static function configuredRedirectUris(): array
    {
        $configured = config('services.latte.redirect_uris');

        if (is_array($configured)) {
            return array_values(array_filter($configured, is_string(...)));
        }

        if (is_string($configured)) {
            $values = preg_split('/\s*,\s*/', trim($configured), -1, PREG_SPLIT_NO_EMPTY);

            return is_array($values) ? $values : [];
        }

        return [];
    }

    private static function originFromUrl(string $url, bool $allowPath): ?string
    {
        $url = trim($url);

        if (strtolower($url) === 'null' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (! $allowPath && (isset($parts['path']) || isset($parts['query'])))
        ) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = self::normalizeHost((string) $parts['host']);

        if (! self::schemeAllowsHost($scheme, $host)) {
            return null;
        }

        $port = self::originPort($parts);
        $defaultPort = self::originPort(['scheme' => $scheme]);

        return $scheme.'://'.$host.($port !== null && $port !== $defaultPort ? ':'.$port : '');
    }

    private static function normalizeHost(string $host): string
    {
        $host = strtolower($host);

        return str_contains($host, ':') && ! str_starts_with($host, '[')
            ? '['.$host.']'
            : $host;
    }

    private static function schemeAllowsHost(string $scheme, string $host): bool
    {
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * @param  array{scheme?: string, port?: int}  $parts
     */
    private static function originPort(array $parts): ?int
    {
        if (isset($parts['port'])) {
            return $parts['port'];
        }

        return match (strtolower($parts['scheme'] ?? '')) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }
}
