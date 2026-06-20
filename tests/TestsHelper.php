<?php

namespace Tests;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

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
        $return = new Request;
        $return = $return->createFromBase(
            SymfonyRequest::create(
                $uri,
                $method,
                $params,
                $cookies,
                $files,
                $server,
                $content
            )
        );
        if (!empty($headers)) {
            $return->headers->replace($headers);
        }
        $return->query->replace($params);
        return $return;
    }

}
