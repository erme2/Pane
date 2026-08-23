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
        self::assertStringContainsString('0.1.0-alpha.1', $doc);
        self::assertStringContainsString('0.1.0-beta.1', $doc);
        self::assertStringContainsString('0.1.0-rc.1', $doc);
        self::assertStringContainsString('0.1.0`', $doc);
        self::assertStringContainsString('Pane tags use `v<version>`', $doc);
        self::assertStringContainsString('Latte tags use `v<version>`', $doc);
        self::assertStringContainsString('Pane v0.1.0-alpha.1 + Latte v0.1.0-alpha.1', $doc);
        self::assertStringContainsString('Compatible release pair:', $doc);
        self::assertStringContainsString('Cross-app smoke checks:', $doc);
        self::assertStringContainsString('The release note must not contain secret values', $doc);
    }
}
