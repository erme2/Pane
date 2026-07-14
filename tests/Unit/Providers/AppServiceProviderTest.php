<?php

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use RuntimeException;
use Tests\TestCase;

class AppServiceProviderTest extends TestCase
{
    public function test_production_fails_closed_when_debug_is_enabled(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG must be false');

        (new AppServiceProvider($this->app))->register();
    }

    public function test_production_boots_when_debug_is_disabled(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'session.secure' => true,
        ]);

        (new AppServiceProvider($this->app))->register();

        $this->assertFalse(config('app.debug'));
    }

    public function test_non_local_fails_closed_when_secure_session_cookies_are_disabled(): void
    {
        config([
            'app.env' => 'staging',
            'session.secure' => false,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SESSION_SECURE_COOKIE must be true');

        (new AppServiceProvider($this->app))->register();
    }

    public function test_non_local_boots_when_secure_session_cookies_are_enabled(): void
    {
        config([
            'app.env' => 'staging',
            'session.secure' => true,
        ]);

        (new AppServiceProvider($this->app))->register();

        $this->assertTrue(config('session.secure'));
    }

    public function test_local_allows_insecure_session_cookies(): void
    {
        config([
            'app.env' => 'local',
            'session.secure' => false,
        ]);

        (new AppServiceProvider($this->app))->register();

        $this->assertFalse(config('session.secure'));
    }
}
