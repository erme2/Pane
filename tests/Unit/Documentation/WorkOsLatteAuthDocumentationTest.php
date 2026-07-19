<?php

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

class WorkOsLatteAuthDocumentationTest extends TestCase
{
    public function test_workos_latte_auth_documentation_is_linked_and_covers_the_core_contract(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = file_get_contents($root.'/README.md');
        $doc = file_get_contents($root.'/docs/workos-latte-auth.md');

        $this->assertIsString($readme);
        $this->assertIsString($doc);
        $this->assertStringContainsString('docs/workos-latte-auth.md', $readme);
        $this->assertStringContainsString('Pane owns OAuth state generation and validation.', $doc);
        $this->assertStringContainsString('Latte owns browser redirects and callback forwarding.', $doc);
        $this->assertStringContainsString('WORKOS_REDIRECT_URI=https://latte.localhost/auth/callback', $doc);
        $this->assertStringContainsString('Invalid WorkOS state.', $doc);
        $this->assertStringContainsString('Pane does not persist WorkOS access or refresh tokens', $doc);
    }
}
