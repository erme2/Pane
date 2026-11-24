<?php

namespace App\Http\Middleware;

use App\Services\DeviceTokenService;
use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * A native client proves itself with its bearer token, not a cookie, so there is nothing for CSRF to protect
     * (Pane#130). A bad or missing bearer token falls through to the normal check.
     */
    public function handle($request, Closure $next)
    {
        if (app(DeviceTokenService::class)->isDeviceRequest($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/v1/auth/native/exchange',
        'api/v1/auth/native/refresh',
        'auth/callback',
        'api/v1/csrf-cookie',
        'api/v1/auth/login-intents',
        'api/v1/auth/callback',
    ];
}
