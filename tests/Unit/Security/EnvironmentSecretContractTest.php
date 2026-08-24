<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

class EnvironmentSecretContractTest extends TestCase
{
    public function test_committed_environment_templates_do_not_contain_laravel_app_keys(): void
    {
        $root = dirname(__DIR__, 3);
        $forbiddenPrefix = 'APP_KEY='.implode('', ['base', '64']).':';

        foreach (['.env.example', '.env.testing', '.env.docker'] as $path) {
            $contents = file_get_contents($root.'/'.$path);

            self::assertStringNotContainsString($forbiddenPrefix, $contents, $path.' must not contain a committed Laravel APP_KEY.');
            self::assertMatchesRegularExpression('/^APP_KEY=$/m', $contents, $path.' should leave APP_KEY blank.');
        }
    }

    public function test_refresh_script_preserves_inherited_app_key_when_template_is_blank(): void
    {
        $root = dirname(__DIR__, 3);
        $script = file_get_contents($root.'/bash/refresh.sh');

        self::assertStringContainsString('INHERITED_APP_KEY="${APP_KEY:-}"', $script);
        self::assertStringContainsString('[ -z "${APP_KEY:-}" ] && [ -n "${INHERITED_APP_KEY}" ]', $script);
        self::assertStringContainsString('APP_KEY="${INHERITED_APP_KEY}"', $script);
    }

    public function test_local_environment_files_are_excluded_from_docker_build_context(): void
    {
        $root = dirname(__DIR__, 3);
        $dockerignore = file_get_contents($root.'/.dockerignore');

        self::assertMatchesRegularExpression('/^\.env$/m', $dockerignore);
        self::assertMatchesRegularExpression('/^\.env\.\*$/m', $dockerignore);
        self::assertStringContainsString('!.env.example', $dockerignore);
    }

    public function test_hostinger_preflight_reports_secret_keys_without_printing_values(): void
    {
        $root = dirname(__DIR__, 3);
        $script = file_get_contents($root.'/bash/hostinger-preflight.sh');
        $doc = file_get_contents($root.'/docs/hostinger.md');

        self::assertStringContainsString('production environment values are present and safe to report by key name', $script);
        self::assertStringContainsString('FAIL: {$message}', $script);
        self::assertStringContainsString('must not print', $doc);
        self::assertStringContainsString('Do not print or paste `APP_KEY`, WorkOS secrets, database credentials, or', $doc);
        self::assertStringNotContainsString('DB_PASSWORD=${', $script);
        self::assertStringNotContainsString('WORKOS_API_KEY=${', $script);
        self::assertStringNotContainsString('APP_KEY=${', $script);
        self::assertStringNotContainsString('PANE_MANAGED_CREDENTIAL_KEYS=${', $script);
    }
}
