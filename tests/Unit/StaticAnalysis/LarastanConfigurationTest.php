<?php

namespace Tests\Unit\StaticAnalysis;

use PHPUnit\Framework\TestCase;

class LarastanConfigurationTest extends TestCase
{
    public function test_larastan_is_configured_as_a_ci_gate(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = json_decode(file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $config = file_get_contents($root.'/phpstan.neon.dist');
        $workflow = file_get_contents($root.'/.github/workflows/pr-tests.yml');
        $readme = file_get_contents($root.'/README.md');

        self::assertSame('phpstan analyse --no-progress --debug --memory-limit=1G', $composer['scripts']['analyse']);
        self::assertArrayHasKey('larastan/larastan', $composer['require-dev']);
        self::assertArrayNotHasKey('phpstan/phpstan', $composer['require-dev']);

        self::assertStringContainsString('vendor/larastan/larastan/extension.neon', $config);
        self::assertStringContainsString('phpstan-baseline.neon', $config);
        self::assertStringContainsString('level: 5', $config);
        self::assertStringContainsString('- app', $config);
        self::assertStringContainsString('- tests', $config);
        self::assertStringNotContainsString('ignoreErrors', $config);

        self::assertFileExists($root.'/phpstan-baseline.neon');
        self::assertStringContainsString('run: composer analyse', $workflow);
        self::assertStringContainsString('Pane uses Larastan', $readme);
        self::assertStringContainsString('new errors are not covered by the baseline', $readme);
    }
}
