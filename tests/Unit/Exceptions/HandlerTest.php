<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\Handler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

        $data = $this->responseContent($response)['data'];

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

        $data = $this->responseContent($response)['data'];

        $this->assertSame('Local debug error', $data['message']);
        $this->assertArrayHasKey('file', $data);
        $this->assertArrayHasKey('line', $data);
        $this->assertArrayHasKey('trace', $data);
    }

    public function test_csrf_token_mismatch_returns_page_expired(): void
    {
        $response = $this->handler()->render(
            Request::create('/crud/test_table', 'POST'),
            new TokenMismatchException('CSRF token mismatch.')
        );

        $content = $this->responseContent($response);

        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame('Page Expired', $content['status']);
        $this->assertSame('CSRF token mismatch.', $content['data']['message']);
    }

    public function test_v1_authentication_exception_returns_error_envelope(): void
    {
        $requestId = (string) Str::uuid();
        $request = Request::create('/api/v1/session', 'GET');
        $request->headers->set('X-Request-Id', $requestId);

        $response = $this->handler()->render($request, new AuthenticationException);
        $content = $this->responseContent($response);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertSame($requestId, $response->headers->get('X-Request-Id'));
        $this->assertSame('authentication_required', $content['error']['code']);
        $this->assertSame($requestId, $content['error']['request_id']);
        $this->assertArrayNotHasKey('status', $content);
        $this->assertArrayNotHasKey('data', $content);
    }

    public function test_v1_csrf_token_mismatch_returns_forbidden_error_envelope(): void
    {
        $request = Request::create('/api/v1/session', 'DELETE');

        $response = $this->handler()->render($request, new TokenMismatchException('CSRF token mismatch.'));
        $content = $this->responseContent($response);

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
        $this->assertSame('csrf_failed', $content['error']['code']);
        $this->assertSame($response->headers->get('X-Request-Id'), $content['error']['request_id']);
        $this->assertArrayNotHasKey('status', $content);
        $this->assertArrayNotHasKey('data', $content);
    }

    public function test_v1_not_found_exception_returns_resource_not_found_envelope(): void
    {
        $request = Request::create('/api/v1/missing', 'GET');

        $response = $this->handler()->render($request, new NotFoundHttpException);
        $content = $this->responseContent($response);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
        $this->assertSame('resource_not_found', $content['error']['code']);
        $this->assertSame($response->headers->get('X-Request-Id'), $content['error']['request_id']);
        $this->assertArrayNotHasKey('status', $content);
        $this->assertArrayNotHasKey('data', $content);
    }

    private function handler(): Handler
    {
        return $this->app->make(Handler::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseContent(\Symfony\Component\HttpFoundation\Response $response): array
    {
        if ($response instanceof Response) {
            $content = $response->getOriginalContent();

            return is_array($content) ? $content : [];
        }

        $content = json_decode($response->getContent(), true);

        return is_array($content) ? $content : [];
    }
}
