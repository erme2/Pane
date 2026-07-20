<?php

namespace Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;

class PaneV1ContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $contract;

    private string $documentation;

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__, 3);
        $contract = json_decode(
            (string) file_get_contents($root.'/contracts/pane-v1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($contract);
        $this->contract = $contract;
        $this->documentation = (string) file_get_contents($root.'/docs/api-v1.md');
    }

    public function test_contract_defines_versioned_installation_and_organization_routes(): void
    {
        $this->assertSame('/api/v1', $this->contract['base_path']);
        $this->assertArrayHasKey('/installation/organizations', $this->contract['paths']);
        $this->assertArrayHasKey(
            '/organizations/{organization_id}/connections/{connection_id}/tables/{table_id}/rows/{row_key}',
            $this->contract['paths'],
        );
        $this->assertSame('uuid', $this->contract['identifiers']['resources']);
        $this->assertSame('string', $this->contract['identifiers']['row_key']);
    }

    public function test_application_context_is_fixed_before_resources_are_resolved(): void
    {
        $this->assertSame('X-Pane-Application-Id', $this->contract['application']['header']);
        $this->assertSame('registered_application', $this->contract['application']['organization_source']);
        $this->assertFalse($this->contract['application']['browser_organization_selection']);

        $order = $this->contract['organization_resolution_order'];
        $this->assertLessThan(
            array_search('organization_owned_resource', $order, true),
            array_search('fixed_organization_match', $order, true),
        );
        $this->assertLessThan(
            array_search('organization_owned_resource', $order, true),
            array_search('active_membership_and_role', $order, true),
        );
    }

    public function test_envelopes_pagination_concurrency_and_errors_are_stable(): void
    {
        $this->assertContains('meta.request_id', $this->contract['envelopes']['success']['required']);
        $this->assertSame(100, $this->contract['pagination']['maximum_limit']);
        $this->assertSame('If-Match', $this->contract['concurrency']['request_header']);
        $this->assertSame(412, $this->contract['concurrency']['conflict_status']);
        $this->assertContains('validation_failed', $this->contract['error_codes']['422']);
        $this->assertContains('internal_error', $this->contract['error_codes']['500']);
    }

    public function test_contract_forbids_unsafe_inputs_and_excludes_dynamic_routes(): void
    {
        $this->assertContains('raw_sql', $this->contract['forbidden_inputs']);
        $this->assertContains('physical_identifier', $this->contract['forbidden_inputs']);
        $this->assertContains('connection_secret_response', $this->contract['forbidden_inputs']);
        $this->assertContains('browser_selected_organization', $this->contract['forbidden_inputs']);
        $this->assertFalse($this->contract['legacy']['part_of_v1']);
        $this->assertFalse($this->contract['legacy']['new_capabilities_allowed']);
    }

    public function test_documentation_defines_browser_security_and_legacy_migration(): void
    {
        $this->assertStringContainsString('X-XSRF-TOKEN', $this->documentation);
        $this->assertStringContainsString('trusted origin', strtolower($this->documentation));
        $this->assertStringContainsString('Production messages are safe', $this->documentation);
        $this->assertStringContainsString('Compatibility and removal policy', $this->documentation);
        $this->assertStringContainsString('No new subject or capability', $this->documentation);
    }
}
