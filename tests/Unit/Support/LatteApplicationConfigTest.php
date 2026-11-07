<?php

namespace Tests\Unit\Support;

use App\Support\LatteApplicationConfig;
use Tests\TestCase;

class LatteApplicationConfigTest extends TestCase
{
    public function test_trusted_origin_from_url_normalizes_frontend_url(): void
    {
        $this->assertSame(
            'https://latte.test',
            LatteApplicationConfig::trustedOriginFromUrl('https://LATTE.test:443/app')
        );
    }

    public function test_configured_cors_origin_uses_normalized_latte_default(): void
    {
        $this->assertSame(['https://latte.localhost'], config('cors.allowed_origins'));
    }

    public function test_extra_frontend_origins_parse_origins_and_redirect_uris(): void
    {
        config()->set('services.latte.frontend_url', 'https://app.test');
        config()->set(
            'services.latte.extra_frontend_origins',
            ' https://ADMIN.test:443 | https://admin.test/auth/callback , https://admin.test/dashboard ; https://other.test ;'
        );

        $this->assertSame([
            'https://admin.test' => ['https://admin.test/auth/callback', 'https://admin.test/dashboard'],
            'https://other.test' => ['https://other.test/auth/callback'],
        ], LatteApplicationConfig::extraFrontendOrigins());
    }

    public function test_extra_frontend_origins_skip_invalid_and_configured_origins(): void
    {
        config()->set('services.latte.frontend_url', 'https://app.test');
        config()->set(
            'services.latte.extra_frontend_origins',
            'http://insecure.test;not-a-url;https://app.test|https://app.test/auth/callback;https://admin.test|javascript:alert(1)'
        );

        $this->assertSame(
            ['https://admin.test' => ['https://admin.test/auth/callback']],
            LatteApplicationConfig::extraFrontendOrigins()
        );
    }

    public function test_extra_frontend_origins_default_to_none(): void
    {
        config()->set('services.latte.extra_frontend_origins', null);

        $this->assertSame([], LatteApplicationConfig::extraFrontendOrigins());
    }
}
