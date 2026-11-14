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
        $this->assertStringContainsString('actions/cache@v6', $workflow);
        $this->assertStringContainsString('composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader', $workflow);
        $this->assertStringContainsString('./bash/test.sh -o no -f no', $workflow);
        $this->assertStringContainsString("\n  workflow_call:\n", $workflow);
        $this->assertStringContainsString("if: \${{ github.event_name == 'pull_request' }}\n        run: composer audit --locked --no-interaction", $workflow);
    }

    public function test_pr_workflow_runs_managed_database_integration_tests_on_mysql_and_mariadb(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/pr-tests.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString("\n  integration:\n", $workflow);
        $this->assertStringContainsString('image: mysql:8.4', $workflow);
        $this->assertStringContainsString('image: mariadb:11.4', $workflow);
        $this->assertStringContainsString('PANE_IT_DB_HOST: 127.0.0.1', $workflow);
        // Fails instead of passing quietly if the tests can't find the server.
        $this->assertStringContainsString("--filter='RealManagedDatabaseTest|RealPrefixUnprefixedTablesTest' --fail-on-skipped", $workflow);
    }

    public function test_tag_workflow_tags_merged_prs_after_tests_one_at_a_time(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/tag-main.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString("push:\n    branches: [main]", $workflow);
        $this->assertStringContainsString('./bash/release-merged-pr.sh "$GITHUB_SHA"', $workflow);
        $this->assertStringContainsString('sparse-checkout: bash', $workflow);
        $this->assertStringContainsString('uses: ./.github/workflows/pr-tests.yml', $workflow);
        $this->assertStringContainsString('needs: [merged-pr, test]', $workflow);
        $this->assertStringContainsString("group: pane-release-tag\n      cancel-in-progress: false", $workflow);
        $this->assertStringContainsString('fetch-depth: 0', $workflow);
        $this->assertStringContainsString('./bash/release-tag.sh -c "$GITHUB_SHA" -g ./bash/release-tag-gate.sh', $workflow);
        $this->assertStringContainsString("permissions:\n      checks: read\n      contents: write\n      pull-requests: read", $workflow);
        $this->assertStringContainsString('GH_TOKEN: ${{ github.token }}', $workflow);

        // The gate waits on each earlier merge's "Test main / test" check run,
        // named after the caller job and the reusable workflow's job.
        $gate = file_get_contents(dirname(__DIR__, 2).'/bash/release-tag-gate.sh');
        $this->assertIsString($gate);
        $this->assertStringContainsString("  test:\n    name: Test main\n", $workflow);
        $this->assertStringContainsString('CHECK_NAME=${RELEASE_TAG_CHECK_NAME:-Test main / test}', $gate);
    }
}
