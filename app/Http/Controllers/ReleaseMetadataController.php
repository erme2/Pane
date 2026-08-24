<?php

namespace App\Http\Controllers;

use App\Support\ReleaseMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReleaseMetadataController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $requestId = $this->requestId($request);
        $metadata = app(ReleaseMetadata::class)->current();

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
