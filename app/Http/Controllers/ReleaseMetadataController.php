<?php

namespace App\Http\Controllers;

use App\Services\ApplicationRegistryService;
use App\Support\ReleaseMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReleaseMetadataController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        // The public application ID of this install's Latte application. Deploys compare it with the
        // expectedApplicationId they were configured with, to confirm the target is the right install.
        $registry = app(ApplicationRegistryService::class);
        // Every trusted front end has its own application ID; a deploy matches its expectedOrigin
        // against this map, and expectedApplicationId must be the one registered for that origin.
        $applicationIds = [];
        foreach ($registry->applications() as $application) {
            $applicationIds[$application->trusted_origin] = $application->application_id;
        }
        $metadata = app(ReleaseMetadata::class)->current()
            + ['application_id' => $registry->configuredLatteApplication()->application_id]
            + ['application_ids' => $applicationIds];

        return response()->json([
            'data' => [
                'type' => 'release',
                'attributes' => $metadata,
            ],
            'meta' => [
                'request_id' => $requestId,
            ],
        ])->header('X-Request-Id', $requestId);
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->headers->get('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid();
    }
}
