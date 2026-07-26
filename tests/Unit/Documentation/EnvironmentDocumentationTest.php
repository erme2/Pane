<?php

namespace Tests\Unit\Documentation;

use Tests\TestCase;

class EnvironmentDocumentationTest extends TestCase
{
    public function test_environment_documentation_is_linked_and_explains_env_template(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = file_get_contents($root.'/README.md');
        $envExample = file_get_contents($root.'/.env.example');
        $doc = file_get_contents($root.'/docs/environment.md');

        $this->assertStringContainsString('docs/environment.md', $readme);
        $this->assertStringContainsString('docs/environment.md', $envExample);
        $this->assertStringContainsString('APP_URL', $doc);
        $this->assertStringContainsString('TRUSTED_HOSTS', $doc);
        $this->assertStringContainsString('latte.localhost` should not normally be in `TRUSTED_HOSTS`', $doc);
        $this->assertStringContainsString('VITE_PANE_PROXY_HOST=pane.localhost', $doc);
        $this->assertStringContainsString('FRONTEND_URL=https://latte.localhost', $envExample);
        $this->assertStringContainsString('WORKOS_REDIRECT_URI=https://latte.localhost/auth/callback', $envExample);
        $this->assertStringContainsString('WORKOS_REDIRECT_URI', $doc);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE', $doc);
    }
}
