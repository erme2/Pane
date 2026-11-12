<?php

namespace App\Support\Messages;

/**
 * Email subjects on dev and stage start with `[DEV]` / `[STAGE]`, so nobody
 * mistakes them for production mail. Production has no prefix.
 *
 * The environment comes from `PANE_ENVIRONMENT` (dev, stage, prod), because
 * deployed Pane installs run with `APP_ENV=production` even on dev and stage.
 * When it's unset, `APP_ENV` is used: dev/development and stage/staging.
 */
final class SubjectPrefix
{
    public static function apply(string $subject, ?string $environment = null): string
    {
        $prefix = self::for($environment ?? self::environment());

        if ($prefix === null || str_starts_with(ltrim($subject), $prefix)) {
            return $subject;
        }

        return "$prefix $subject";
    }

    public static function for(string $environment): ?string
    {
        return match (strtolower(trim($environment))) {
            'dev', 'development' => '[DEV]',
            'stage', 'staging' => '[STAGE]',
            default => null,
        };
    }

    public static function environment(): string
    {
        $configured = config('services.messages.environment');

        return is_string($configured) && trim($configured) !== '' ? $configured : (string) config('app.env');
    }
}
