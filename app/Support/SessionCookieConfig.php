<?php

namespace App\Support;

final class SessionCookieConfig
{
    private const INSECURE_ALLOWED_ENVIRONMENTS = [
        'local',
        'testing',
    ];

    public static function secure(mixed $configured, string $environment): bool
    {
        if ($configured !== null) {
            return (bool) $configured;
        }

        return self::secureByDefault($environment);
    }

    public static function secureByDefault(string $environment): bool
    {
        return ! self::allowsInsecureSessionCookies($environment);
    }

    public static function allowsInsecureSessionCookies(string $environment): bool
    {
        return in_array($environment, self::INSECURE_ALLOWED_ENVIRONMENTS, true);
    }
}
