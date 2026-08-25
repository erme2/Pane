<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HostingerDeployWorkflowTest extends TestCase
{
    private string $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/deploy-hostinger.yml');

        $this->assertIsString($workflow);
        $this->workflow = $workflow;
    }

    public function test_deploy_workflow_runs_after_cleanup_success_or_manual_dispatch_and_uses_protected_environment_secrets(): void
    {
        $this->assertStringContainsString("workflow_run:\n    workflows:\n      - Cleanup stale branches and pull requests", $this->workflow);
        $this->assertStringContainsString("types:\n      - completed", $this->workflow);
        $this->assertStringContainsString("branches:\n      - main", $this->workflow);
        $this->assertStringContainsString('workflow_dispatch:', $this->workflow);
        $this->assertStringContainsString('type: environment', $this->workflow);
        $this->assertStringContainsString("if: \${{ github.event_name == 'workflow_dispatch' || github.event.workflow_run.conclusion == 'success' }}", $this->workflow);
        $this->assertStringContainsString("environment: \${{ github.event_name == 'workflow_dispatch' && inputs.environment || 'production' }}", $this->workflow);
        $this->assertStringContainsString("group: pane-hostinger-\${{ github.event_name == 'workflow_dispatch' && inputs.environment || 'production' }}", $this->workflow);
        $this->assertStringContainsString("PANE_SOURCE_REF_NAME: \${{ github.event_name == 'workflow_dispatch' && github.ref_name || github.event.workflow_run.head_branch }}", $this->workflow);
        $this->assertStringContainsString("PANE_SOURCE_SHA: \${{ github.event_name == 'workflow_dispatch' && github.sha || github.event.workflow_run.head_sha }}", $this->workflow);
        $this->assertStringContainsString("ref: \${{ github.event_name == 'workflow_dispatch' && github.ref || github.event.workflow_run.head_sha }}", $this->workflow);
        $this->assertStringNotContainsString("push:\n    branches:\n      - main", $this->workflow);

        foreach ([
            'secrets.PANE_PRODUCTION_ENV',
            'secrets.PANE_HOSTINGER_HOST',
            'secrets.PANE_HOSTINGER_USER',
            'secrets.PANE_HOSTINGER_PORT',
            'secrets.PANE_HOSTINGER_SSH_KEY',
            'secrets.PANE_HOSTINGER_DEPLOY_PATH',
        ] as $secret) {
            $this->assertStringContainsString($secret, $this->workflow);
        }
    }

    public function test_deploy_workflow_retries_hostinger_ssh_host_key_scan(): void
    {
        $this->assertStringContainsString('for attempt in 1 2 3; do', $this->workflow);
        $this->assertStringContainsString('ssh-keyscan -T 20 -p "$PANE_HOSTINGER_PORT" -H "$PANE_HOSTINGER_HOST"', $this->workflow);
        $this->assertStringContainsString('host_key_scanned=yes', $this->workflow);
        $this->assertStringContainsString('if [ "$host_key_scanned" != "yes" ]; then', $this->workflow);
        $this->assertStringContainsString('Hostinger SSH host key scan failed; retrying', $this->workflow);
        $this->assertStringContainsString('Unable to scan Hostinger SSH host key', $this->workflow);
    }

    public function test_deploy_workflow_does_not_publish_environment_files_as_artifacts(): void
    {
        $this->assertStringContainsString('echo "PANE_ENV_FILE=$RUNNER_TEMP/pane-production.env" >> "$GITHUB_ENV"', $this->workflow);
        $this->assertStringNotContainsString('PANE_ENV_FILE: $RUNNER_TEMP/pane-production.env', $this->workflow);
        $this->assertStringNotContainsString('PANE_ENV_FILE: ${{ runner.temp }}/pane-production.env', $this->workflow);
        $this->assertStringContainsString('printf \'%s\\n\' "${PANE_PRODUCTION_ENV}" > "$PANE_ENV_FILE"', $this->workflow);
        $this->assertStringContainsString('rm -f "$PANE_ENV_FILE" ~/.ssh/pane_hostinger', $this->workflow);
        $this->assertStringNotContainsString('actions/upload-artifact', $this->workflow);
        $this->assertStringContainsString("--exclude='.env'", $this->workflow);
        $this->assertStringContainsString("--exclude='.env.*'", $this->workflow);
    }

    public function test_deploy_workflow_targets_prepared_hostinger_directory_and_runs_release_checks(): void
    {
        $this->assertStringContainsString('/home/u253124519/public_html/pane', $this->workflow);
        $this->assertStringContainsString('./bash/hostinger-preflight.sh -e "$PANE_ENV_FILE" -d no', $this->workflow);
        $this->assertStringContainsString("./bash/hostinger-preflight.sh -e .env -d '\$CHECK_DATABASE'", $this->workflow);
        $this->assertStringContainsString('php artisan release:cache', $this->workflow);
        $this->assertStringContainsString('php artisan migrate --force', $this->workflow);
        $this->assertStringContainsString('php artisan config:cache', $this->workflow);
        $this->assertStringContainsString('php artisan route:cache', $this->workflow);
        $this->assertStringContainsString('if [ -d resources/views ]; then php artisan view:cache; else echo \'No Blade views to cache\'; fi', $this->workflow);
        $this->assertStringContainsString('PANE_PRODUCTION_URL: https://pane.erme2.com', $this->workflow);
        $this->assertStringContainsString('curl --fail --silent --show-error --location "$PANE_PRODUCTION_URL/"', $this->workflow);
        $this->assertStringContainsString('$PANE_PRODUCTION_URL/docs/openapi.json', $this->workflow);
        $this->assertStringContainsString('OpenAPI contract smoke check failed', $this->workflow);
        $this->assertStringContainsString('$PANE_PRODUCTION_URL/api/v1/release', $this->workflow);
        $this->assertStringContainsString('https://github.com/erme2/Pane/issues/99', $this->workflow);
    }

    public function test_deploy_metadata_rejects_shell_significant_release_and_ref_values(): void
    {
        $this->assertStringContainsString('validate_release_value()', $this->workflow);
        $this->assertStringContainsString('*[!A-Za-z0-9._/@+-]*)', $this->workflow);
        $this->assertStringContainsString('$name contains unsupported characters', $this->workflow);
        $this->assertStringContainsString("RELEASE_VERSION_INPUT: \${{ github.event_name == 'workflow_dispatch' && inputs.release_version || '' }}", $this->workflow);
        $this->assertStringContainsString('release_version="$RELEASE_VERSION_INPUT"', $this->workflow);
        $this->assertStringContainsString('release_version="0.1.0-alpha.$GITHUB_RUN_NUMBER"', $this->workflow);
        $this->assertStringContainsString('release_version="${PANE_SOURCE_REF_NAME#v}"', $this->workflow);
        $this->assertStringNotContainsString('release_version="${{ inputs.release_version }}"', $this->workflow);
        $this->assertStringContainsString('validate_release_value release_version "$release_version"', $this->workflow);
        $this->assertStringContainsString('validate_release_value PANE_SOURCE_REF_NAME "$PANE_SOURCE_REF_NAME"', $this->workflow);
        $this->assertStringContainsString('GITHUB_RUN_NUMBER contains unsupported characters', $this->workflow);
        $this->assertStringContainsString('PANE_SOURCE_SHA contains unsupported characters', $this->workflow);
    }

    public function test_cleanup_success_deployments_check_database_and_run_migrations_by_default(): void
    {
        $this->assertStringContainsString("CHECK_DATABASE: \${{ (github.event_name == 'workflow_run' || inputs.check_database) && 'yes' || 'no' }}", $this->workflow);
        $this->assertStringContainsString("RUN_MIGRATIONS: \${{ (github.event_name == 'workflow_run' || inputs.run_migrations) && 'yes' || 'no' }}", $this->workflow);
        $this->assertStringContainsString("echo \"- Environment: \$PANE_DEPLOY_ENV\"", $this->workflow);
        $this->assertStringContainsString("echo \"- Migrations: \${{ (github.event_name == 'workflow_run' || inputs.run_migrations) && 'run' || 'skipped' }}\"", $this->workflow);
    }
}
