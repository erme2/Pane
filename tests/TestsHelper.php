<?php

namespace Tests;

trait TestsHelper
{
    public function createMockRequest(
        string $uri = '/',
        string $method = 'GET',
        array $params = [],
        array $cookies = [],
        array $files = [],
        array $server = [
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
        ],
        string $content = null
    )
    {
        $return = new \Illuminate\Http\Request;
        return $return->createFromBase(
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

    }

}
