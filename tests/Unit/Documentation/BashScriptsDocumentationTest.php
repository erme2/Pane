<?php

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

class BashScriptsDocumentationTest extends TestCase
{
    public function test_bash_scripts_documentation_is_linked_and_covers_commands(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = file_get_contents($root.'/README.md');
        $doc = file_get_contents($root.'/docs/bash.md');
        $clear = file_get_contents($root.'/bash/clear.sh');
        $refresh = file_get_contents($root.'/bash/refresh.sh');
        $test = file_get_contents($root.'/bash/test.sh');

        self::assertStringContainsString('docs/bash.md', $readme);
        self::assertStringContainsString('bash/clear.sh', $doc);
        self::assertStringContainsString('bash/generate-certs.sh', $doc);
        self::assertStringContainsString('bash/refresh.sh', $doc);
        self::assertStringContainsString('bash/test.sh', $doc);
        self::assertStringContainsString('This script is destructive when database deletion is enabled.', $doc);
        self::assertStringContainsString('./bash/test.sh -o no -f no', $doc);
        self::assertStringContainsString('nginx/certs/localhost.pem', $doc);
        self::assertStringContainsString('database/migrations/test', $doc);
        self::assertStringContainsString('TestTableSeeder', $doc);
        self::assertStringContainsString('cp .env.testing .env', $doc);
        self::assertStringContainsString('Error: .env file not found.', $doc);
        self::assertStringContainsString('the default test command also requires the root `.env` file', $doc);
        foreach ([$clear, $refresh, $test] as $script) {
            self::assertStringNotContainsString('TODO - document this file', $script);
            self::assertStringNotContainsString('TO DO - document this file', $script);
        }
    }
}
