<?php

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

class ArchitectureDocumentationTest extends TestCase
{
    public function test_architecture_documentation_is_linked_and_separates_current_from_planned(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = file_get_contents($root.'/README.md');
        $doc = file_get_contents($root.'/docs/architecture.md');
        $api = file_get_contents($root.'/docs/api-v1.md');
        $decision = file_get_contents($root.'/docs/decisions/2026-11-13-product-installs-and-description-layer.md');

        self::assertIsString($readme);
        self::assertIsString($doc);
        self::assertIsString($api);
        self::assertIsString($decision);
        self::assertStringContainsString('docs/architecture.md', $readme);
        self::assertStringContainsString('## Current component map', $doc);
        self::assertStringContainsString('## Gaps and planned work', $doc);
        self::assertStringContainsString('## Invariants and forbidden dependencies', $doc);
        self::assertStringContainsString('## Before making changes', $doc);
        self::assertStringContainsString('One install = one organization', $doc);
        self::assertStringContainsString('app:install', $doc);
        self::assertStringContainsString('docs/product-architecture.md', $doc);
        self::assertStringContainsString('## Planned', $api);
        self::assertStringContainsString('superseded in part', $decision);
    }

    public function test_architecture_documents_no_removed_features_as_current(): void
    {
        $root = dirname(__DIR__, 3);
        $doc = (string) file_get_contents($root.'/docs/architecture.md');
        $api = (string) file_get_contents($root.'/docs/api-v1.md');

        foreach (['/organizations/{organization_id}', 'pane_membership_id', 'connection grants', 'impersonation'] as $removed) {
            self::assertStringNotContainsString($removed, $doc);
        }

        self::assertStringNotContainsString('/organizations/{organization_id}', $api);
    }
}
