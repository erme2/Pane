<?php

namespace Tests\Feature;

use Illuminate\Http\Response;
use Tests\TestCase;

class IndexTest extends TestCase
{
    public function test_root_redirects_to_docs(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/docs');
    }

    public function test_docs_render_openapi_documentation(): void
    {
        $response = $this->get('/docs');

        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertSee('Pane REST API')
            ->assertSee('SwaggerUIBundle')
            ->assertSee('/docs/openapi.json');
    }

    public function test_docs_openapi_contract_is_public(): void
    {
        $response = $this->getJson('/docs/openapi.json');

        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('info.title', 'Pane phase-one HTTP API');
    }

    public function test_status_requires_basic_authentication(): void
    {
        $response = $this->getJson('/status');

        $response
            ->assertStatus(Response::HTTP_UNAUTHORIZED)
            ->assertHeader('WWW-Authenticate', 'Basic realm="Pane Status"')
            ->assertHeader('Cache-Control');
    }

    public function test_status_returns_uncached_release_metadata_with_valid_basic_authentication(): void
    {
        $response = $this
            ->withHeader('Authorization', 'Basic '.base64_encode('pane:status-secret'))
            ->getJson('/status');

        $response
            ->assertStatus(Response::HTTP_OK)
            ->assertHeader('Cache-Control')
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('data.message', 'Pane RestAPI is available')
            ->assertJsonPath('data.release.application', 'pane');
    }

    public function test_status_fails_closed_when_password_is_missing(): void
    {
        config(['app.status_password' => null]);

        $response = $this
            ->withHeader('Authorization', 'Basic '.base64_encode('pane:status-secret'))
            ->getJson('/status');

        $response
            ->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
            ->assertHeader('Cache-Control')
            ->assertJsonPath('message', 'Pane status password is not configured.');
    }
}
