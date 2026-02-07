<?php

namespace Tests;

use Symfony\Component\HttpFoundation\HeaderBag;

trait TestsHelper
{
    const CHECK_ERROR_MESSAGES = ' (this is required to test error messages)';

    public function createMockRequest(
        string $uri = '/',
        string $method = 'GET',
        array $params = [],
        array $headers = [],
        array $cookies = [],
        array $files = [],
        array $server = [
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
        ],
        ?string $content = null
    )
    {
        $return = new \Illuminate\Http\Request;
        $return = $return->createFromBase(
            \Symfony\Component\HttpFoundation\Request::create(
                $uri,
                $method,
                $params,
                $cookies,
                $files,
                $server,
                $content
            )
        );
        $return->headers = new HeaderBag($headers);
        $return->query->replace($params);
        return $return;
    }

}
