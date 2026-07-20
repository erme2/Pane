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

    public function test_contract_is_openapi_with_versioned_route_families(): void
    {
        $this->assertSame('3.1.0', $this->contract['openapi']);
        $this->assertSame('/api/v1', $this->contract['servers'][0]['url']);
        $this->assertArrayHasKey('/installation/organizations', $this->contract['paths']);
        $this->assertArrayHasKey(
            '/organizations/{organization_id}/connections/{connection_id}/tables/{table_id}/rows/{row_key}',
            $this->contract['paths'],
        );
    }

    public function test_browser_application_is_bound_at_login_and_reloaded_from_session(): void
    {
        $resolution = $this->contract['x-pane-application-resolution'];

        $this->assertSame('origin', $resolution['login_source']);
        $this->assertSame('server_session_application_id', $resolution['authenticated_source']);
        $this->assertTrue($resolution['reload_active_registration_each_request']);
        $this->assertTrue($resolution['origin_required_on_mutations']);
        $this->assertTrue($resolution['origin_validated_when_present_on_reads']);
        $this->assertTrue($resolution['active_origin_globally_unique']);
        $this->assertFalse($resolution['client_application_header_allowed']);
        $this->assertStringNotContainsString('X-Pane-Application-Id', json_encode($this->contract, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey(
            'organization_id',
            $this->contract['components']['schemas']['ApplicationUpdate']['properties'],
        );
    }

    public function test_application_or_impersonation_context_precedes_resource_resolution(): void
    {
        $order = $this->contract['x-pane-organization-resolution-order'];

        $this->assertLessThan(
            array_search('organization_owned_resource', $order, true),
            array_search('application_or_impersonation_organization_match', $order, true),
        );
        $this->assertLessThan(
            array_search('organization_owned_resource', $order, true),
            array_search('active_membership_and_role', $order, true),
        );
        $this->assertStringContainsString('impersonation target', $this->documentation);
    }

    public function test_authentication_csrf_and_invitation_activation_are_versioned(): void
    {
        $this->assertArrayHasKey('/csrf-cookie', $this->contract['paths']);
        $this->assertArrayHasKey('/auth/login-intents', $this->contract['paths']);
        $this->assertArrayHasKey('/auth/callback', $this->contract['paths']);
        $this->assertArrayHasKey('/session', $this->contract['paths']);
        $this->assertSame(
            '#/components/schemas/LoginIntentInput',
            $this->contract['paths']['/auth/login-intents']['post']['requestBody']['content']['application/json']['schema']['$ref'],
        );
        $this->assertTrue($this->contract['components']['schemas']['LoginIntentInput']['properties']['invitation_token']['writeOnly']);
        $this->assertArrayHasKey('post', $this->contract['paths']['/csrf-cookie']);
        $this->assertArrayNotHasKey('get', $this->contract['paths']['/csrf-cookie']);
    }

    public function test_every_operation_has_an_id_and_declares_responses(): void
    {
        $operationCount = 0;

        foreach ($this->contract['paths'] as $path => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $operationCount++;
                $this->assertNotEmpty($operation['operationId'], $path.' '.$method.' needs operationId');
                $this->assertNotEmpty($operation['responses'], $path.' '.$method.' needs responses');
                $this->assertContains(
                    '#/components/parameters/RequestId',
                    array_column($operation['parameters'] ?? [], '$ref'),
                    $path.' '.$method.' needs the request ID input',
                );

                foreach ($operation['responses'] as $response) {
                    if (isset($response['$ref'])) {
                        $name = basename($response['$ref']);
                        $response = $this->contract['components']['responses'][$name];
                    }

                    $this->assertArrayHasKey(
                        'X-Request-Id',
                        $response['headers'],
                        $path.' '.$method.' response needs the request ID output',
                    );
                }
            }
        }

        $this->assertGreaterThanOrEqual(35, $operationCount);
    }

    public function test_mutations_define_request_schema_and_csrf_except_bodyless_actions(): void
    {
        $login = $this->contract['paths']['/auth/login-intents']['post'];
        $connection = $this->contract['paths']['/organizations/{organization_id}/connections']['post'];
        $grant = $this->contract['paths']['/organizations/{organization_id}/connections/{connection_id}/grants/{membership_id}']['put'];

        $this->assertArrayHasKey('requestBody', $login);
        $this->assertArrayHasKey('requestBody', $connection);
        $this->assertArrayHasKey('requestBody', $grant);
        $this->assertContains(
            '#/components/parameters/XsrfToken',
            array_column($connection['parameters'], '$ref'),
        );
    }

    public function test_connection_secrets_are_write_only_and_absent_from_responses(): void
    {
        $schemas = $this->contract['components']['schemas'];

        $this->assertTrue($schemas['CredentialsWrite']['writeOnly']);
        $this->assertArrayNotHasKey('credentials', $schemas['ConnectionResource']['properties']['attributes']['properties']);
        $this->assertArrayHasKey('credentials_configured', $schemas['ConnectionResource']['properties']['attributes']['properties']);
        $this->assertContains('credentials', $schemas['ConnectionCreate']['required']);
        $this->assertContains('password', $schemas['CredentialsCreate']['required']);
    }

    public function test_domain_responses_use_specific_resource_schemas(): void
    {
        $responses = $this->contract['components']['responses'];

        foreach ([
            'Organization' => 'OrganizationResource',
            'Application' => 'ApplicationResource',
            'Membership' => 'MembershipResource',
            'Invitation' => 'InvitationResource',
            'Impersonation' => 'ImpersonationResource',
            'Grant' => 'GrantResource',
            'CatalogObject' => 'CatalogObjectResource',
            'Row' => 'RowResource',
        ] as $response => $schema) {
            $this->assertSame(
                '#/components/schemas/'.$schema,
                $responses[$response]['content']['application/json']['schema']['properties']['data']['$ref'],
            );
        }

        $this->assertSame(
            '#/components/schemas/AuditEventResource',
            $responses['AuditEventCollection']['content']['application/json']['schema']['properties']['data']['items']['$ref'],
        );
    }

    public function test_create_schemas_reject_invalid_application_invitation_and_empty_resources(): void
    {
        $schemas = $this->contract['components']['schemas'];

        $this->assertCount(2, $schemas['ApplicationCreate']['oneOf']);
        $this->assertSame('latte', $schemas['ApplicationCreate']['oneOf'][0]['properties']['kind']['const']);
        $this->assertContains('organization_id', $schemas['ApplicationCreate']['oneOf'][0]['required']);
        $this->assertSame('burro', $schemas['ApplicationCreate']['oneOf'][1]['properties']['kind']['const']);
        $this->assertArrayNotHasKey('organization_id', $schemas['ApplicationCreate']['oneOf'][1]['properties']);
        $this->assertSame(
            ['organization_administrator', 'organization_user'],
            $schemas['OrganizationInvitationCreate']['properties']['role']['enum'],
        );
        $this->assertArrayNotHasKey('role', $schemas['PaneAdminInvitationCreate']['properties']);
        $this->assertContains('name', $schemas['OrganizationCreate']['required']);
        $this->assertContains('host', $schemas['ConnectionCreate']['required']);
    }

    public function test_invitation_expiry_is_resolved_from_settings_not_the_request(): void
    {
        $schemas = $this->contract['components']['schemas'];

        $this->assertArrayNotHasKey('expires_in_seconds', $schemas['PaneAdminInvitationCreate']['properties']);
        $this->assertArrayNotHasKey('expires_in_seconds', $schemas['OrganizationInvitationCreate']['properties']);
        $this->assertStringContainsString('never accepts a caller-selected expiry', $this->documentation);
    }

    public function test_pagination_concurrency_row_keys_and_grants_are_exact(): void
    {
        $parameters = $this->contract['components']['parameters'];

        $this->assertSame(100, $parameters['PageLimit']['schema']['maximum']);
        $this->assertSame('If-Match', $parameters['IfMatch']['name']);
        $this->assertStringContainsString('base64url', $parameters['RowKey']['description']);
        $this->assertArrayHasKey(
            '/organizations/{organization_id}/connections/{connection_id}/grants/{membership_id}',
            $this->contract['paths'],
        );
    }

    public function test_contract_forbids_unsafe_inputs_and_excludes_legacy_routes(): void
    {
        $forbidden = $this->contract['x-pane-forbidden-inputs'];

        $this->assertContains('raw_sql', $forbidden);
        $this->assertContains('physical_identifier', $forbidden);
        $this->assertContains('connection_secret_response', $forbidden);
        $this->assertContains('browser_selected_application', $forbidden);
        $this->assertContains('browser_selected_organization', $forbidden);
        $this->assertFalse($this->contract['x-pane-legacy']['part_of_v1']);
        $this->assertFalse($this->contract['x-pane-legacy']['new_capabilities_allowed']);
    }
}
