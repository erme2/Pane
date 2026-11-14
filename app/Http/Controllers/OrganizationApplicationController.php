<?php

namespace App\Http\Controllers;

use App\Models\ApplicationRegistration;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Lists the organization's active frontends so organization administrators can choose
 * which one an invitation opens (target_application_id).
 */
class OrganizationApplicationController extends Controller
{
    public function list(Request $request, string $organizationId): JsonResponse
    {
        $organization = $request->attributes->get('pane_v1_organization');
        $membership = $request->attributes->get('pane_v1_membership');

        if (
            ! $organization instanceof Organization
            || ! $membership instanceof OrganizationMembership
            || ! $membership->isAdministrator()
        ) {
            return $this->v1Error($request, 'permission_denied', 'Only active organization administrators can list organization frontends.', Response::HTTP_FORBIDDEN);
        }

        $applications = ApplicationRegistration::query()
            ->where('kind', ApplicationRegistration::KIND_LATTE)
            ->where('organization_id', $organization->getKey())
            ->where('status', ApplicationRegistration::STATUS_ACTIVE)
            ->orderBy('name')
            ->orderBy('application_id')
            ->get();
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $applications
                ->map(fn (ApplicationRegistration $application): array => [
                    'id' => (string) $application->getKey(),
                    'type' => 'organization_application',
                    'attributes' => [
                        'name' => $application->name,
                        'trusted_origin' => $application->trusted_origin,
                    ],
                ])
                ->values(),
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId);
    }

    private function v1Error(Request $request, string $code, string $message, int $status): JsonResponse
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
