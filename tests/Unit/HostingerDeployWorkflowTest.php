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

    public function test_deploy_workflow_is_manual_and_uses_protected_environment_secrets(): void
    {
        $this->assertStringContainsString('workflow_dispatch:', $this->workflow);
        $this->assertStringContainsString('type: environment', $this->workflow);
        $this->assertStringContainsString('environment: ${{ inputs.environment }}', $this->workflow);

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
        $this->assertStringContainsString('/home/u253124519/domains/erme2.com/public_html/pane', $this->workflow);
        $this->assertStringContainsString('./bash/hostinger-preflight.sh -e "$PANE_ENV_FILE" -d no', $this->workflow);
        $this->assertStringContainsString("./bash/hostinger-preflight.sh -e .env -d '\$CHECK_DATABASE'", $this->workflow);
        $this->assertStringContainsString('php artisan release:cache', $this->workflow);
        $this->assertStringContainsString('php artisan migrate --force', $this->workflow);
        $this->assertStringContainsString('php artisan config:cache', $this->workflow);
        $this->assertStringContainsString('php artisan route:cache', $this->workflow);
        $this->assertStringContainsString('if [ -d resources/views ]; then php artisan view:cache; else echo \'No Blade views to cache\'; fi', $this->workflow);
        $this->assertStringContainsString('PANE_PRODUCTION_URL: https://pane.erme2.com', $this->workflow);
        $this->assertStringContainsString('$PANE_PRODUCTION_URL/api/v1/release', $this->workflow);
        $this->assertStringContainsString('https://github.com/erme2/Pane/issues/99', $this->workflow);
    }

    public function test_deploy_metadata_rejects_shell_significant_release_and_ref_values(): void
    {
        $this->assertStringContainsString('validate_release_value()', $this->workflow);
        $this->assertStringContainsString('*[!A-Za-z0-9._/@+-]*)', $this->workflow);
        $this->assertStringContainsString('$name contains unsupported characters', $this->workflow);
        $this->assertStringContainsString('RELEASE_VERSION_INPUT: ${{ inputs.release_version }}', $this->workflow);
        $this->assertStringContainsString('release_version="$RELEASE_VERSION_INPUT"', $this->workflow);
        $this->assertStringNotContainsString('release_version="${{ inputs.release_version }}"', $this->workflow);
        $this->assertStringContainsString('validate_release_value release_version "$release_version"', $this->workflow);
        $this->assertStringContainsString('validate_release_value GITHUB_REF_NAME "$GITHUB_REF_NAME"', $this->workflow);
        $this->assertStringContainsString('GITHUB_SHA contains unsupported characters', $this->workflow);
    }
}
