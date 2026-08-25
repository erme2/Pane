<?php

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

class HostingerRuntimeDocumentationTest extends TestCase
{
    public function test_hostinger_runtime_documentation_is_linked_and_covers_preflight(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = file_get_contents($root.'/README.md');
        $bashDoc = file_get_contents($root.'/docs/bash.md');
        $hostingerDoc = file_get_contents($root.'/docs/hostinger.md');
        $script = file_get_contents($root.'/bash/hostinger-preflight.sh');

        self::assertStringContainsString('docs/hostinger.md', $readme);
        self::assertStringContainsString('bash/hostinger-preflight.sh', $bashDoc);
        self::assertStringContainsString('PHP 8.5 or newer', $hostingerDoc);
        self::assertStringContainsString("Laravel's `public/` directory", $hostingerDoc);
        self::assertStringContainsString('Composer available over SSH', $hostingerDoc);
        self::assertStringContainsString('MySQL or MariaDB reachable', $hostingerDoc);
        self::assertStringContainsString('APP_ENV=production', $hostingerDoc);
        self::assertStringContainsString('APP_DEBUG=false', $hostingerDoc);
        self::assertStringContainsString('APP_URL=https://pane.erme2.com', $hostingerDoc);
        self::assertStringContainsString('FRONTEND_URL=https://latte.erme2.com', $hostingerDoc);
        self::assertStringContainsString('SESSION_SECURE_COOKIE=true', $hostingerDoc);
        self::assertStringContainsString('PANE_STATUS_PASSWORD=<strong random password>', $hostingerDoc);
        self::assertStringContainsString('./bash/hostinger-preflight.sh -e .env.production -d no', $hostingerDoc);
        self::assertStringContainsString('temporary `.env.*` file', $hostingerDoc);
        self::assertStringContainsString('TEMP_LARAVEL_ENV_NAME="hostinger-preflight-$$"', $script);
        self::assertStringContainsString('php artisan config:clear --env="${TEMP_LARAVEL_ENV_NAME}"', $script);
        self::assertStringContainsString('composer check-platform-reqs --no-dev', $script);
        self::assertStringContainsString('PDO($dsn', $script);
        self::assertStringContainsString('PANE_MANAGED_CREDENTIAL_KEYS', $script);
        self::assertStringContainsString('PANE_STATUS_PASSWORD', $script);
    }
}
