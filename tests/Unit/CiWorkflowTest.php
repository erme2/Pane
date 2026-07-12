<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CiWorkflowTest extends TestCase
{
    public function test_pr_workflow_runs_tests_for_pull_request_lifecycle_events_only(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/pr-tests.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('pull_request:', $workflow);
        $this->assertStringContainsString('types: [opened, reopened, ready_for_review, synchronize]', $workflow);
        $this->assertStringNotContainsString("\npush:", $workflow);
        $this->assertStringContainsString('actions/cache@v4', $workflow);
        $this->assertStringContainsString('composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader', $workflow);
        $this->assertStringContainsString('./bash/test.sh -o no -f no', $workflow);
    }
}
