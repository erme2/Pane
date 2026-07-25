<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

class LocalRuntimeOwnershipTest extends TestCase
{
    public function test_pane_local_runtime_does_not_own_frontend_nginx(): void
    {
        $root = dirname(__DIR__, 3);
        $compose = file_get_contents($root.'/docker-compose.yml');
        $readme = file_get_contents($root.'/README.md');
        $environment = file_get_contents($root.'/docs/environment.md');

        self::assertIsString($compose);
        self::assertIsString($readme);
        self::assertIsString($environment);

        self::assertFileDoesNotExist($root.'/nginx/default.conf');
        self::assertFileDoesNotExist($root.'/bash/generate-certs.sh');

        self::assertStringNotContainsString('nginx:', $compose);
        self::assertStringNotContainsString('nginx/default.conf', $compose);
        self::assertStringNotContainsString('latte.localhost', $compose);
        self::assertStringNotContainsString('burro.localhost', $compose);

        self::assertStringContainsString('Latte-derived frontends own their browser hostnames, HTTPS certificates, and', $readme);
        self::assertStringContainsString('`/pane` proxy.', $readme);
        self::assertStringContainsString('Pane does not run frontend Nginx vhosts for Latte-derived apps.', $environment);
    }
}
