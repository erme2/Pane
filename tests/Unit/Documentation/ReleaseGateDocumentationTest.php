<?php

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

class ReleaseGateDocumentationTest extends TestCase
{
    public function test_release_gate_is_linked_and_covers_the_cross_app_checks(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = file_get_contents($root.'/README.md');
        $releaseDoc = file_get_contents($root.'/docs/release.md');
        $doc = file_get_contents($root.'/docs/release-gate.md');

        self::assertIsString($readme);
        self::assertIsString($releaseDoc);
        self::assertIsString($doc);
        self::assertStringContainsString('docs/release-gate.md', $readme);
        self::assertStringContainsString('(release-gate.md)', $releaseDoc);
        self::assertStringContainsString('Release gate (docs/release-gate.md)', $releaseDoc);

        self::assertStringContainsString('A release is **ready** only when every item below is checked.', $doc);
        self::assertStringContainsString('`FRONTEND_URL` is the Latte production origin', $doc);
        self::assertStringContainsString('`https://latte.erme2.com/auth/callback`', $doc);
        self::assertStringContainsString('`SESSION_SECURE_COOKIE=true`', $doc);
        self::assertStringContainsString('`paneBaseUrl` set to the Pane', $doc);
        self::assertStringContainsString('php artisan managed-credentials:check', $doc);

        foreach (['Tests:', 'Static analysis:', 'Dependency and security audit:', 'Migrations reviewed:', 'Secrets generated or rotated:', 'Backups confirmed:', 'Smoke checks passed:'] as $check) {
            self::assertStringContainsString('- [ ] '.$check, $doc);
        }

        self::assertStringContainsString('### Pane (`.env` on the host', $doc);
        self::assertStringContainsString('### Latte (static build', $doc);
    }

    public function test_release_gate_lists_every_key_the_preflight_requires(): void
    {
        $root = dirname(__DIR__, 3);
        $doc = file_get_contents($root.'/docs/release-gate.md');
        $script = file_get_contents($root.'/bash/hostinger-preflight.sh');

        self::assertIsString($doc);
        self::assertIsString($script);
        self::assertSame(1, preg_match('/\$required = \[(.*?)\];/s', $script, $match));
        self::assertGreaterThan(0, preg_match_all("/'([A-Z_]+)'/", $match[1], $keys));

        foreach ($keys[1] as $key) {
            self::assertStringContainsString('`'.$key.'`', $doc, "{$key} is required by the preflight but missing from the release gate");
        }
    }
}
