<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'workos' => [
        'api_key' => env('WORKOS_API_KEY'),
        'client_id' => env('WORKOS_CLIENT_ID'),
        'redirect_uri' => env('WORKOS_REDIRECT_URI'),
        'return_to' => env('WORKOS_RETURN_TO', env('APP_URL')),
        'provider' => env('WORKOS_PROVIDER', 'authkit'),
        'organization_id' => env('WORKOS_ORGANIZATION_ID'),
        'connection_id' => env('WORKOS_CONNECTION_ID'),
    ],

];
