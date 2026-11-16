<?php

namespace App\Http\Controllers;

use App\Models\OrganizationMembership;
use App\Services\MapDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * v1 maps discovery report (Pane#155): how the install's own app tables differ
 * from the maps. Organization administrators only. Computed on each request
 * and stored nowhere; it never changes maps or tables.
 */
class MapDiscoveryController extends Controller
{
    public function __construct(private readonly MapDiscovery $discovery) {}

    public function show(Request $request, string $organizationId): JsonResponse
    {
        $requestId = $this->requestId($request);
        $membership = $request->attributes->get('pane_v1_membership');

        if (! $membership instanceof OrganizationMembership || ! $membership->isAdministrator()) {
            return response()->json([
                'error' => [
                    'code' => 'permission_denied',
                    'message' => 'Only active organization administrators can see the maps discovery report.',
                    'request_id' => $requestId,
                ],
            ], Response::HTTP_FORBIDDEN)->header('X-Request-Id', $requestId);
        }

        return response()->json([
            'data' => ['type' => 'map_discovery', 'attributes' => $this->discovery->report()],
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)->header('Cache-Control', 'no-store');
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId) ? $requestId : (string) Str::uuid();
    }
}
