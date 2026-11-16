<?php

namespace Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

class RollbackRunbookDocumentationTest extends TestCase
{
    public function test_rollback_runbook_is_linked_and_covers_the_release_safety_steps(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = file_get_contents($root.'/README.md');
        $release = file_get_contents($root.'/docs/release.md');
        $runbook = file_get_contents($root.'/docs/rollback.md');

        self::assertIsString($readme);
        self::assertIsString($release);
        self::assertIsString($runbook);
        self::assertStringContainsString('docs/rollback.md', $readme);
        self::assertStringContainsString('(rollback.md)', $release);

        self::assertStringContainsString('## Backup Checkpoint Before Migrations', $runbook);
        self::assertStringContainsString('Production migrations are forward-only.', $runbook);
        self::assertStringContainsString('php artisan migrate:rollback', $runbook);
        self::assertStringContainsString('### Restoring a Backup', $runbook);
        self::assertStringContainsString('## Rolling Back the Application', $runbook);
        self::assertStringContainsString('Hostinger keeps no previous release.', $runbook);
        self::assertStringContainsString('/api/v1/release', $runbook);
        self::assertStringContainsString('/latte-release.json', $runbook);
        self::assertStringContainsString('## Leaked or Misconfigured Secrets', $runbook);
        self::assertStringContainsString('APP_PREVIOUS_KEYS', $runbook);
        self::assertStringContainsString('## Hostinger Directories', $runbook);
        self::assertStringContainsString('/home/u253124519/public_html/pane', $runbook);
        self::assertStringContainsString('/home/u253124519/domains/erme2.com/public_html/latte', $runbook);
    }

    public function test_rollback_runbook_documents_the_pane_deploy_paths_it_relies_on(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = file_get_contents($root.'/.github/workflows/deploy-hostinger.yml');
        $runbook = file_get_contents($root.'/docs/rollback.md');

        self::assertIsString($workflow);
        self::assertIsString($runbook);
        self::assertStringContainsString('rsync -az --delete', $workflow);
        self::assertStringContainsString("--exclude='storage/'", $workflow);
        self::assertStringContainsString("--exclude='.env'", $workflow);
        self::assertStringContainsString('"/home/u253124519/public_html/pane"', $workflow);
        self::assertStringContainsString('Deploy Pane to Hostinger', $runbook);
    }
}
