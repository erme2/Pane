<?php

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

class ReleasePolicyDocumentationTest extends TestCase
{
    public function test_release_policy_is_linked_and_covers_versioning_tags_and_notes(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = file_get_contents($root.'/README.md');
        $doc = file_get_contents($root.'/docs/release.md');

        self::assertIsString($readme);
        self::assertIsString($doc);
        self::assertStringContainsString('docs/release.md', $readme);
        self::assertStringContainsString('Every merge to `main` bumps the patch.', $doc);
        self::assertStringContainsString('`v0.1.0-alpha.4` is the same release as `v0.1.4`', $doc);
        self::assertStringContainsString('Pane tags use `v<version>`, for example `v0.1.5`', $doc);
        self::assertStringContainsString('Latte tags use `v<version>`', $doc);
        self::assertStringContainsString('Pane v0.1.0-alpha.1 + Latte v0.1.0-alpha.1', $doc);
        self::assertStringContainsString('Compatible release pair:', $doc);
        self::assertStringContainsString('Cross-app smoke checks:', $doc);
        self::assertStringContainsString('The release note must not contain secret values', $doc);
        self::assertStringContainsString('## Automatic Tags', $doc);
        self::assertStringContainsString('bash/release-tag.sh', $doc);
        self::assertStringContainsString('Starting a new minor or major version stays a manual step.', $doc);
    }
}
