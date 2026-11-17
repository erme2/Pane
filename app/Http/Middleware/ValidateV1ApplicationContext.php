<?php

namespace App\Http\Middleware;

use App\Models\ApplicationRegistration;
use App\Services\ApplicationRegistryService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the v1 data routes (maps) tied to the frontend the session was created for.
 */
class ValidateV1ApplicationContext
{
    private const V1_APPLICATION_SESSION_KEY = 'pane_v1_application_id';

    private const V1_APPLICATION_SESSION_VERSION_KEY = 'pane_v1_application_session_version';

    public function __construct(private readonly ApplicationRegistryService $applications) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/maps', 'api/v1/maps/*')) {
            return $next($request);
        }

        $applicationId = $request->session()->get(self::V1_APPLICATION_SESSION_KEY);

        $application = is_string($applicationId)
            ? $this->applications->activeApplicationForSessionRequest(
                $applicationId,
                $request->session()->get(self::V1_APPLICATION_SESSION_VERSION_KEY),
                $request->headers->get('Origin')
            )
            : null;

        if (! $application instanceof ApplicationRegistration) {
            return $this->v1Error($request, 'application_not_allowed', 'The application origin is not allowed.', Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('pane_v1_application', $application);

        return $next($request);
    }

    private function v1Error(Request $request, string $code, string $message, int $status): Response
    {
        $requestId = $request->header('X-Request-Id');
        $requestId = is_string($requestId) && Str::isUuid($requestId) ? $requestId : (string) Str::uuid();

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $requestId,
            ],
        ], $status)->header('X-Request-Id', $requestId);
    }
}
