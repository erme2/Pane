<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    private function preflight(string $path, string $origin)
    {
        return $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
        ])->call('OPTIONS', $path);
    }

    public function test_crud_routes_answer_cors_for_an_allowed_origin(): void
    {
        $origin = config('cors.allowed_origins')[0];

        $response = $this->preflight('/crud/lists', $origin);

        $response->assertHeader('Access-Control-Allow-Origin', $origin);
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_crud_routes_do_not_answer_cors_for_an_unknown_origin(): void
    {
        $response = $this->preflight('/crud/lists', 'https://unknown.example');

        $this->assertNotSame('https://unknown.example', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
