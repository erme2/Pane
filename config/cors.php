<?php

use App\Support\LatteApplicationConfig;

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['auth/*', 'api/v1/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        LatteApplicationConfig::trustedOriginFromUrl((string) env('FRONTEND_URL', 'https://latte.localhost'))
            ?? 'https://latte.localhost',
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 0,

    'supports_credentials' => true,

];
