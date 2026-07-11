<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
