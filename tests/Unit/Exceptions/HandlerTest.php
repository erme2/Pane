<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\Handler;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

class HandlerTest extends TestCase
{
    public function test_production_debug_errors_do_not_include_internal_details(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => true,
        ]);

        $response = $this->handler()->render(
            Request::create('/api/test', 'GET'),
            new RuntimeException('Unsafe production configuration')
        );

        $data = $response->getOriginalContent()['data'];

        $this->assertSame('Unsafe production configuration', $data['message']);
        $this->assertArrayNotHasKey('file', $data);
        $this->assertArrayNotHasKey('line', $data);
        $this->assertArrayNotHasKey('trace', $data);
    }

    public function test_non_production_debug_errors_include_internal_details(): void
    {
        config([
            'app.env' => 'local',
            'app.debug' => true,
        ]);

        $response = $this->handler()->render(
            Request::create('/api/test', 'GET'),
            new RuntimeException('Local debug error')
        );

        $data = $response->getOriginalContent()['data'];

        $this->assertSame('Local debug error', $data['message']);
        $this->assertArrayHasKey('file', $data);
        $this->assertArrayHasKey('line', $data);
        $this->assertArrayHasKey('trace', $data);
    }

    private function handler(): Handler
    {
        return $this->app->make(Handler::class);
    }
}
