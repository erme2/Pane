<?php

namespace App\Http\Middleware;

use App\Support\LatteApplicationConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ValidateV1Origin
{
    private const V1_APPLICATION_SESSION_KEY = 'pane_v1_application_id';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/*')) {
            return $next($request);
        }

        $requiresOrigin = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        if (! $requiresOrigin && ! $request->headers->has('Origin')) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');

        if (! is_string($origin) || ! $this->originMatchesRequestApplication($request, $origin)) {
            return $this->applicationNotAllowed($request);
        }

        return $next($request);
    }

    private function originMatchesRequestApplication(Request $request, string $origin): bool
    {
        $sessionApplicationId = $request->session()->get(self::V1_APPLICATION_SESSION_KEY);

        if ($sessionApplicationId === null) {
            return ($this->isBootstrapRequest($request) || $request->user() === null)
                && LatteApplicationConfig::isAllowedOrigin($origin);
        }

        if (! is_string($sessionApplicationId) || ! hash_equals($this->currentLatteApplicationId(), $sessionApplicationId)) {
            return false;
        }

        return LatteApplicationConfig::isAllowedOrigin($origin);
    }

    private function isBootstrapRequest(Request $request): bool
    {
        return $request->is('api/v1/csrf-cookie')
            || $request->is('api/v1/auth/login-intents')
            || $request->is('api/v1/auth/callback');
    }

    private function currentLatteApplicationId(): string
    {
        return (string) config('services.latte.application_id');
    }

    private function applicationNotAllowed(Request $request): Response
    {
        $requestId = $this->requestId($request);

        return response()->json([
            'error' => [
                'code' => 'application_not_allowed',
                'message' => 'The application origin is not allowed.',
                'request_id' => $requestId,
            ],
        ], Response::HTTP_FORBIDDEN)->header('X-Request-Id', $requestId);
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid();
    }
}
