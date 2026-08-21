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
}
