<?php

namespace Tests\Feature;

use App\Support\ReleaseMetadata;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ReleaseMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('release.cache_path', '/private/tmp/pane-release-test-'.getmypid().'.php');
        app(ReleaseMetadata::class)->clearCache();
    }

    protected function tearDown(): void
    {
        app(ReleaseMetadata::class)->clearCache();

        parent::tearDown();
    }

    public function test_release_metadata_endpoint_returns_safe_fallbacks_without_authentication(): void
    {
        Config::set('app.name', 'pane');

        $requestId = '5f744502-83f0-4426-b765-eb1968fb9c5b';

        $response = $this
            ->withHeader('X-Request-Id', $requestId)
            ->getJson('/api/v1/release')
            ->assertOk()
            ->assertHeader('X-Request-Id', $requestId)
            ->assertJsonPath('data.type', 'release')
            ->assertJsonPath('data.attributes.application', 'pane')
            ->assertJsonPath('data.attributes.built_at', null)
            ->assertJsonPath('meta.request_id', $requestId);

        $this->assertIsString($response->json('data.attributes.version'));
        $this->assertNotSame('', $response->json('data.attributes.version'));
        $this->assertIsString($response->json('data.attributes.ref'));
        $this->assertNotSame('', $response->json('data.attributes.ref'));
    }

    public function test_release_metadata_endpoint_returns_github_or_override_build_metadata_only(): void
    {
        Config::set('app.name', 'pane');
        app(ReleaseMetadata::class)->writeCache([
            'application' => 'pane',
            'version' => '0.1.0-alpha.1',
            'ref' => 'refs/tags/v0.1.0-alpha.1',
            'commit' => '7c83789df0e3d87cb5bf77bd7c3f05124a7a85a7',
            'built_at' => '2026-08-24T15:45:00Z',
        ]);
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
        Config::set('services.workos.api_key', 'sk_live_secret');

        $response = $this
            ->getJson('/api/v1/release')
            ->assertOk()
            ->assertJsonPath('data.attributes.application', 'pane')
            ->assertJsonPath('data.attributes.version', '0.1.0-alpha.1')
            ->assertJsonPath('data.attributes.ref', 'refs/tags/v0.1.0-alpha.1')
            ->assertJsonPath('data.attributes.commit', '7c83789df0e3d87cb5bf77bd7c3f05124a7a85a7')
            ->assertJsonPath('data.attributes.built_at', '2026-08-24T15:45:00Z');

        $payload = $response->getContent();

        $this->assertIsString($payload);
        $this->assertStringNotContainsString('secret-app-key', $payload);
        $this->assertStringNotContainsString('sk_live_secret', $payload);
    }

    public function test_release_metadata_can_be_cached_from_artisan_options(): void
    {
        $this
            ->artisan('release:cache', [
                '--release-version' => '0.1.0-alpha.1',
                '--ref' => 'v0.1.0-alpha.1',
                '--commit' => '7c83789df0e3d87cb5bf77bd7c3f05124a7a85a7',
                '--built-at' => '2026-08-24T15:45:00Z',
                '--cache-path' => app(ReleaseMetadata::class)->cachePath(),
            ])
            ->assertSuccessful();

        $metadata = require app(ReleaseMetadata::class)->cachePath();

        $this->assertSame('0.1.0-alpha.1', $metadata['version']);
        $this->assertSame('v0.1.0-alpha.1', $metadata['ref']);
        $this->assertSame('7c83789df0e3d87cb5bf77bd7c3f05124a7a85a7', $metadata['commit']);
        $this->assertSame('2026-08-24T15:45:00Z', $metadata['built_at']);
    }
}
