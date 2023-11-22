<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Tests\TestCase;

class IndexTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');
        $response->assertStatus(Response::HTTP_OK);
        $data = $response->getOriginalContent();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('status', $data);
        $this->assertEquals('OK', $data['status']);
        $this->assertIsArray($data['data']);
        $this->assertArrayHasKey('message', $data['data']);
        $this->assertEquals('Welcome to Pane RestAPI', $data['data']['message']);
    }
}
