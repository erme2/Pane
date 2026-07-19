<?php

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

class ArchitectureDocumentationTest extends TestCase
{
    public function test_architecture_documentation_is_linked_and_separates_current_from_target(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = file_get_contents($root.'/README.md');
        $doc = file_get_contents($root.'/docs/architecture.md');

        self::assertIsString($readme);
        self::assertIsString($doc);
        self::assertStringContainsString('docs/architecture.md', $readme);
        self::assertStringContainsString('## Current component map', $doc);
        self::assertStringContainsString('## Target domain and state ownership', $doc);
        self::assertStringContainsString('## Current-to-target gap map', $doc);
        self::assertStringContainsString('## Invariants and forbidden dependencies', $doc);
        self::assertStringContainsString('## Before making changes', $doc);
        self::assertStringContainsString('information_schema', $doc);
        self::assertStringContainsString('pane_membership_id', $doc);
        self::assertStringContainsString('docs/product-architecture.md', $doc);
    }
}
