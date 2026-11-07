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
        $this->assertStringContainsString("\n  workflow_call:\n", $workflow);
    }

    public function test_tag_workflow_tags_merged_prs_after_tests_one_at_a_time(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/tag-main.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString("push:\n    branches: [main]", $workflow);
        $this->assertStringContainsString('repos/$GITHUB_REPOSITORY/commits/$GITHUB_SHA/pulls', $workflow);
        $this->assertStringContainsString('select(.merged_at != null and .base.ref == "main")', $workflow);
        $this->assertStringContainsString('uses: ./.github/workflows/pr-tests.yml', $workflow);
        $this->assertStringContainsString('needs: [merged-pr, test]', $workflow);
        $this->assertStringContainsString("group: pane-release-tag\n      cancel-in-progress: false", $workflow);
        $this->assertStringContainsString('fetch-depth: 0', $workflow);
        $this->assertStringContainsString('./bash/release-tag.sh -c "$GITHUB_SHA"', $workflow);
        $this->assertStringContainsString("permissions:\n      contents: write", $workflow);
    }
}
