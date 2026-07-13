<?php

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

class CrudAuthenticationDocumentationTest extends TestCase
{
    public function test_crud_authentication_documentation_is_linked_and_covers_the_core_contract(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = file_get_contents($root.'/README.md');
        $doc = file_get_contents($root.'/docs/crud-authentication.md');

        $this->assertIsString($readme);
        $this->assertIsString($doc);
        $this->assertStringContainsString('docs/crud-authentication.md', $readme);
        $this->assertStringContainsString('CRUD endpoints require an authenticated Laravel session.', $doc);
        $this->assertStringContainsString('Pane does not call WorkOS again for each CRUD request.', $doc);
        $this->assertStringContainsString('Unauthenticated', $doc);
        $this->assertStringContainsString('Authenticated non-admin', $doc);
        $this->assertStringContainsString('Authenticated administrator', $doc);
        $this->assertStringContainsString('400 Invalid WorkOS state', $doc);
    }
}
