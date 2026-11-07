<?php

namespace Tests\Feature\ApplicationRegistry;

use App\Models\ApplicationRegistration;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\OrganizationTenancyService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdditionalFrontendsTest extends TestCase
{
    private const ADMIN_ORIGIN = 'https://admin.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        ApplicationRegistration::query()->delete();
        OrganizationMembership::query()->delete();
        Organization::query()->delete();
        User::query()->delete();

        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://app.example.test/auth/callback');
        config()->set('services.workos.return_to', 'https://app.example.test/');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.latte.frontend_url', 'https://app.example.test');
        config()->set('services.latte.redirect_uris', 'https://app.example.test/auth/callback');
        config()->set('services.latte.additional_frontends', self::ADMIN_ORIGIN.'|'.self::ADMIN_ORIGIN.'/auth/callback,'.self::ADMIN_ORIGIN.'/dashboard');
        config()->set('cors.allowed_origins', ['https://app.example.test']);
    }

    public function test_additional_frontend_logs_in_with_its_own_callback_in_the_configured_organization(): void
    {
        $response = $this
            ->withHeader('Origin', self::ADMIN_ORIGIN)
            ->postJson('/api/v1/auth/login-intents', ['redirect_to' => self::ADMIN_ORIGIN.'/dashboard'])
            ->assertOk();

        $application = app(ApplicationRegistryService::class)->activeApplicationForOrigin(self::ADMIN_ORIGIN);

        $this->assertNotNull($application);
        $this->assertSame(ApplicationRegistration::KIND_LATTE, $application->kind);
        $this->assertSame(config('services.latte.organization_id'), $application->organization_id);
        $this->assertSame([self::ADMIN_ORIGIN.'/auth/callback', self::ADMIN_ORIGIN.'/dashboard'], $application->redirect_uris);
        $response->assertSessionHas('pane_v1_application_id', $application->application_id);
        $this->assertStringContainsString(
            'redirect_uri='.urlencode(self::ADMIN_ORIGIN.'/auth/callback'),
            (string) $response->json('data.authorization_url')
        );
    }

    public function test_configured_frontend_keeps_the_configured_workos_callback(): void
    {
        $response = $this
            ->withHeader('Origin', 'https://app.example.test')
            ->postJson('/api/v1/auth/login-intents', ['redirect_to' => 'https://app.example.test/auth/callback'])
            ->assertOk();

        $this->assertStringContainsString(
            'redirect_uri='.urlencode('https://app.example.test/auth/callback'),
            (string) $response->json('data.authorization_url')
        );
    }

    public function test_additional_frontend_rejects_redirects_outside_its_own_list(): void
    {
        $this
            ->withHeader('Origin', self::ADMIN_ORIGIN)
            ->postJson('/api/v1/auth/login-intents', ['redirect_to' => 'https://app.example.test/auth/callback'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'redirect_not_allowed');
    }

    public function test_cors_allows_every_active_frontend_and_no_other_origin(): void
    {
        $organization = app(OrganizationTenancyService::class)->createOrganization('Other', 'other-'.Str::uuid());
        ApplicationRegistration::query()->create([
            'name' => 'Registered App',
            'kind' => ApplicationRegistration::KIND_LATTE,
            'organization_id' => $organization->organization_id,
            'trusted_origin' => 'https://registered.example.test',
            'redirect_uris' => ['https://registered.example.test/auth/callback'],
            'status' => ApplicationRegistration::STATUS_ACTIVE,
        ]);

        foreach (['https://app.example.test', self::ADMIN_ORIGIN, 'https://registered.example.test'] as $origin) {
            $this->preflight($origin)
                ->assertNoContent()
                ->assertHeader('Access-Control-Allow-Origin', $origin)
                ->assertHeader('Access-Control-Allow-Credentials', 'true');
        }

        $this->assertOriginNotAllowed('https://evil.example.test');
    }

    public function test_logout_returns_to_the_frontend_that_started_it(): void
    {
        $application = app(ApplicationRegistryService::class)->activeApplicationForOrigin(self::ADMIN_ORIGIN);
        $this->assertNotNull($application);

        $this->withCsrfToken()->actingAs($this->makePaneUser(2));

        $response = $this
            ->withV1ApplicationSession($application)
            ->withSession(['workos_session_id' => 'session_123'])
            ->withHeader('Origin', self::ADMIN_ORIGIN)
            ->deleteJson('/api/v1/session')
            ->assertOk();

        $this->assertStringContainsString(
            'return_to='.urlencode(self::ADMIN_ORIGIN.'/'),
            (string) $response->json('data.logout_url')
        );
    }

    public function test_frontends_of_the_same_organization_share_one_session(): void
    {
        $this->withCsrfToken()->actingAs($this->makePaneUser(2));

        // Signed in on the FRONTEND_URL app, then used from the admin frontend.
        $response = $this
            ->withV1ApplicationSession()
            ->withSession(['workos_session_id' => 'session_123'])
            ->withHeader('Origin', self::ADMIN_ORIGIN)
            ->deleteJson('/api/v1/session')
            ->assertOk();

        $this->assertStringContainsString(
            'return_to='.urlencode(self::ADMIN_ORIGIN.'/'),
            (string) $response->json('data.logout_url')
        );
    }

    public function test_session_is_not_shared_with_frontends_of_another_organization(): void
    {
        $organization = app(OrganizationTenancyService::class)->createOrganization('Other', 'other-'.Str::uuid());
        ApplicationRegistration::query()->create([
            'name' => 'Other App',
            'kind' => ApplicationRegistration::KIND_LATTE,
            'organization_id' => $organization->organization_id,
            'trusted_origin' => 'https://other.example.test',
            'redirect_uris' => ['https://other.example.test/auth/callback'],
            'status' => ApplicationRegistration::STATUS_ACTIVE,
        ]);

        $this->withCsrfToken()->actingAs($this->makePaneUser(2));

        $this
            ->withV1ApplicationSession()
            ->withSession(['workos_session_id' => 'session_123'])
            ->withHeader('Origin', 'https://other.example.test')
            ->deleteJson('/api/v1/session')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed');
    }

    public function test_removing_a_frontend_from_config_disables_it_and_adding_it_back_restores_it(): void
    {
        $registry = app(ApplicationRegistryService::class);
        $application = $registry->activeApplicationForOrigin(self::ADMIN_ORIGIN);
        $this->assertNotNull($application);
        $sessionVersion = $application->session_version;

        config()->set('services.latte.additional_frontends', null);

        $this->assertNull($registry->activeApplicationForOrigin(self::ADMIN_ORIGIN));
        $this->assertNull($registry->activeApplicationForId($application->application_id));
        $this->assertOriginNotAllowed(self::ADMIN_ORIGIN);
        $this
            ->withHeader('Origin', self::ADMIN_ORIGIN)
            ->postJson('/api/v1/csrf-cookie')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'application_not_allowed');

        config()->set('services.latte.additional_frontends', self::ADMIN_ORIGIN);

        $restored = $registry->activeApplicationForOrigin(self::ADMIN_ORIGIN);
        $this->assertNotNull($restored);
        $this->assertSame($application->application_id, $restored->application_id);
        $this->assertNotSame($sessionVersion, $restored->session_version);
        $this->assertSame([self::ADMIN_ORIGIN.'/auth/callback'], $restored->redirect_uris);
    }

    public function test_frontend_disabled_through_the_api_stays_disabled_while_still_configured(): void
    {
        $registry = app(ApplicationRegistryService::class);
        $application = $registry->activeApplicationForOrigin(self::ADMIN_ORIGIN);
        $this->assertNotNull($application);

        $registry->disable($this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID), $application);

        $this->assertNull($registry->activeApplicationForOrigin(self::ADMIN_ORIGIN));
        $this->assertNull($registry->activeApplicationForId($application->application_id));
    }

    // With a single configured origin the CORS library always echoes that origin, so check the
    // requested one is not granted rather than that the header is missing.
    private function assertOriginNotAllowed(string $origin): void
    {
        $this->assertSame(
            'https://app.example.test',
            $this->preflight($origin)->headers->get('Access-Control-Allow-Origin')
        );
    }

    private function preflight(string $origin): TestResponse
    {
        return $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/v1/auth/login-intents');
    }
}
