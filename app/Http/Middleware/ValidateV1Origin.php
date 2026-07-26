<?php

namespace App\Http\Middleware;

use App\Support\LatteApplicationConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ValidateV1Origin
{
    private const V1_APPLICATION_SESSION_KEY = 'pane_v1_application';

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
        $trustedOrigin = $this->sessionTrustedOrigin($request);

        if ($trustedOrigin === null) {
            return LatteApplicationConfig::isAllowedOrigin($origin);
        }

        return LatteApplicationConfig::normalizeOrigin($origin) === $trustedOrigin;
    }

    private function sessionTrustedOrigin(Request $request): ?string
    {
        $application = $request->session()->get(self::V1_APPLICATION_SESSION_KEY);

        if (! is_array($application) || ! is_string($application['trusted_origin'] ?? null)) {
            return null;
        }

        return LatteApplicationConfig::normalizeOrigin($application['trusted_origin']);
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
