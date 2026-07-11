<?php

namespace Tests\Unit\Services;

use App\Services\WorkOsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkOsServiceTest extends TestCase
{
    public function test_it_builds_an_authkit_authorization_url(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://pane.test/auth/callback');
        config()->set('services.workos.provider', 'authkit');
        config()->set('services.workos.organization_id', 'org_123');
        config()->set('services.workos.connection_id', 'conn_123');

        $url = (new WorkOsService)->authorizationUrl('state_123');

        $this->assertStringStartsWith('https://api.workos.com/user_management/authorize?', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('client_id=client_123', $url);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fpane.test%2Fauth%2Fcallback', $url);
        $this->assertStringContainsString('state=state_123', $url);
        $this->assertStringContainsString('provider=authkit', $url);
        $this->assertStringNotContainsString('organization_id=', $url);
        $this->assertStringNotContainsString('connection_id=', $url);
    }

    public function test_it_exchanges_an_authorization_code(): void
    {
        config()->set('services.workos.api_key', 'sk_test_123');
        config()->set('services.workos.client_id', 'client_123');
        config()->set('services.workos.redirect_uri', 'https://pane.test/auth/callback');

        Http::fake([
            'https://api.workos.com/user_management/authenticate' => Http::response([
                'user' => ['id' => 'user_123', 'email' => 'user@example.com'],
                'access_token' => 'access_token',
                'refresh_token' => 'refresh_token',
            ]),
        ]);

        $response = (new WorkOsService)->authenticateWithCode(
            'code_123',
            '127.0.0.1',
            'PHPUnit'
        );

        $this->assertSame('user@example.com', $response['user']['email']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.workos.com/user_management/authenticate'
                && $request['client_id'] === 'client_123'
                && $request['client_secret'] === 'sk_test_123'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'code_123'
                && $request['ip_address'] === '127.0.0.1'
                && $request['user_agent'] === 'PHPUnit';
        });
    }
}
