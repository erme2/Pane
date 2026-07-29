<?php

namespace App\Http\Middleware;

use App\Models\ApplicationRegistration;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ValidateV1OrganizationContext
{
    private const V1_APPLICATION_SESSION_KEY = 'pane_v1_application_id';

    public function __construct(private readonly ApplicationRegistryService $applications) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/organizations/*')) {
            return $next($request);
        }

        $organizationId = $request->route('organization_id');

        if (! is_string($organizationId)) {
            $organizationId = $request->route('organizationId');
        }

        if (! is_string($organizationId) || ! Str::isUuid($organizationId)) {
            return $this->v1Error($request, 'invalid_identifier', 'The organization_id path parameter must be a valid UUID.', Response::HTTP_BAD_REQUEST);
        }

        $applicationId = $request->session()->get(self::V1_APPLICATION_SESSION_KEY);

        if (! is_string($applicationId)) {
            return $this->v1Error($request, 'application_not_allowed', 'The application origin is not allowed.', Response::HTTP_FORBIDDEN);
        }

        $application = $this->applications->activeApplicationForId($applicationId);

        if (! $application instanceof ApplicationRegistration) {
            return $this->v1Error($request, 'application_not_allowed', 'The application origin is not allowed.', Response::HTTP_FORBIDDEN);
        }

        if (! $application->isLatte() || ! hash_equals((string) $application->organization_id, $organizationId)) {
            return $this->v1Error($request, 'organization_context_mismatch', 'The route organization does not match the application context.', Response::HTTP_FORBIDDEN);
        }

        $organization = $this->applications->fixedOrganizationFor($application);

        if (! $organization instanceof Organization || ! $organization->isActive()) {
            return $this->v1Error($request, 'organization_inactive', 'The application organization is inactive.', Response::HTTP_FORBIDDEN);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $membership = $organization->activeMembershipFor($user);

        if (! $membership instanceof OrganizationMembership) {
            return $this->v1Error($request, 'membership_required', 'An active organization membership is required.', Response::HTTP_FORBIDDEN);
        }

        $request->attributes->set('pane_v1_application', $application);
        $request->attributes->set('pane_v1_organization', $organization);
        $request->attributes->set('pane_v1_membership', $membership);

        return $next($request);
    }

    private function v1Error(Request $request, string $code, string $message, int $status): Response
    {
        $requestId = $this->requestId($request);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $requestId,
            ],
        ], $status)->header('X-Request-Id', $requestId);
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid();
    }
}
