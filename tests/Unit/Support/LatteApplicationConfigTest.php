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
}
