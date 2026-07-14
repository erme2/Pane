<?php

namespace App\Providers;

use App\Support\SessionCookieConfig;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (
            $this->app['config']->get('app.env') === 'production'
            && $this->app['config']->get('app.debug') === true
        ) {
            throw new RuntimeException('Unsafe production configuration: APP_DEBUG must be false when APP_ENV is production.');
        }

        if (
            ! SessionCookieConfig::allowsInsecureSessionCookies($this->app['config']->get('app.env'))
            && $this->app['config']->get('session.secure') !== true
        ) {
            throw new RuntimeException('Unsafe non-local configuration: SESSION_SECURE_COOKIE must be true unless APP_ENV is local or testing.');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
