<?php

namespace Tests\Unit\Middleware;

use App\Http\Kernel;
use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

class TrustHostsTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function test_trust_hosts_is_global_middleware(): void
    {
        $middleware = (new ReflectionClass(Kernel::class))->getDefaultProperties()['middleware'];

        $this->assertContains(TrustHosts::class, $middleware);
        $this->assertLessThan(
            array_search(TrustProxies::class, $middleware, true),
            array_search(TrustHosts::class, $middleware, true)
        );
    }

    public function test_hosts_include_application_url_and_configured_environments(): void
    {
        config([
            'app.url' => 'https://pane.example.com',
            'app.trusted_hosts' => [
                'pane.localhost',
                'pane.staging.example.com',
                '*.pane.internal',
            ],
        ]);

        $hosts = (new TrustHosts($this->app))->hosts();

        $this->assertContains('^(.+\\.)?pane\\.example\\.com$', $hosts);
        $this->assertContains('^pane\\.localhost$', $hosts);
        $this->assertContains('^pane\\.staging\\.example\\.com$', $hosts);
        $this->assertContains('^(.+\\.)?pane\\.internal$', $hosts);
    }

    public function test_configured_host_is_allowed(): void
    {
        config([
            'app.url' => 'https://pane.example.com',
            'app.trusted_hosts' => ['pane.staging.example.com'],
        ]);

        Request::setTrustedHosts((new TrustHosts($this->app))->hosts());

        $request = Request::create('https://pane.staging.example.com/');

        $this->assertSame('pane.staging.example.com', $request->getHost());
    }

    public function test_untrusted_host_is_rejected(): void
    {
        config([
            'app.url' => 'https://pane.example.com',
            'app.trusted_hosts' => ['pane.staging.example.com'],
        ]);

        Request::setTrustedHosts((new TrustHosts($this->app))->hosts());

        $request = Request::create('https://evil.example.com/');

        $this->expectException(SuspiciousOperationException::class);

        $request->getHost();
    }
}
