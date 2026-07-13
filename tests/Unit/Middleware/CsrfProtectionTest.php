<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as FoundationVerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\Store;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class CsrfProtectionTest extends TestCase
{
    public function test_mutating_crud_request_without_csrf_token_is_rejected(): void
    {
        $this->expectException(TokenMismatchException::class);

        $this->runtimeCsrfMiddleware()->handle(
            $this->requestWithSession('/crud/test_table', Request::METHOD_POST),
            fn () => new Response('', Response::HTTP_NO_CONTENT)
        );
    }

    public function test_mutating_crud_request_accepts_xsrf_header(): void
    {
        $session = $this->startedSession();
        $request = $this->requestWithSession('/crud/test_table', Request::METHOD_POST, $session);
        $request->headers->set('X-XSRF-TOKEN', $this->encryptedXsrfToken($session->token()));

        $response = $this->runtimeCsrfMiddleware()->handle(
            $request,
            fn () => new Response('', Response::HTTP_NO_CONTENT)
        );

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    public function test_workos_callback_is_exempt_from_csrf(): void
    {
        $response = $this->runtimeCsrfMiddleware()->handle(
            $this->requestWithSession('/auth/callback', Request::METHOD_POST),
            fn () => new Response('', Response::HTTP_NO_CONTENT)
        );

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    private function runtimeCsrfMiddleware(): VerifyCsrfToken
    {
        return new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
    }

    private function requestWithSession(string $uri, string $method, ?Store $session = null): Request
    {
        $request = Request::create($uri, $method);
        $request->setLaravelSession($session ?? $this->startedSession());

        return $request;
    }

    private function startedSession(): Store
    {
        $session = $this->app['session.store'];
        $session->start();

        return $session;
    }

    private function encryptedXsrfToken(string $token): string
    {
        return $this->app['encrypter']->encrypt(
            CookieValuePrefix::create('XSRF-TOKEN', $this->app['encrypter']->getKey()).$token,
            FoundationVerifyCsrfToken::serialized()
        );
    }
}
