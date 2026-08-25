<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class TrustedHostAccessTest extends TestCase
{
    private string $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironment = $this->app['env'];
        $this->app->instance('env', 'staging');

        config([
            'app.debug' => false,
            'app.env' => 'staging',
        ]);
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        $this->app->instance('env', $this->originalEnvironment);

        parent::tearDown();
    }

    public function test_app_url_host_can_access_site(): void
    {
        $host = parse_url(config('app.url'), PHP_URL_HOST);

        $this->assertNotEmpty($host);

        $this->getWithHost($host)
            ->assertRedirect('/docs');
    }

    public function test_trusted_hosts_from_env_can_access_site(): void
    {
        $host = config('app.trusted_hosts')[0] ?? null;

        $this->assertNotEmpty($host);

        $this->getWithHost($host)
            ->assertRedirect('/docs');
    }

    public function test_host_missing_from_env_is_rejected(): void
    {
        $host = 'untrusted-pane.testing';

        $this->getWithHost($host)
            ->assertStatus(Response::HTTP_BAD_REQUEST)
            ->assertJsonPath('status', 'Bad Request')
            ->assertJsonPath('data.message', 'Untrusted Host "untrusted-pane.testing".');
    }

    private function getWithHost(string $host)
    {
        return $this->get('https://'.$host.'/');
    }
}
