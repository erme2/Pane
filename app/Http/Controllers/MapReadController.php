<?php

namespace App\Http\Controllers;

use App\Exceptions\Maps\MapNotFoundException;
use App\Models\OrganizationMembership;
use App\Services\MapReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * v1 maps read API (Pane#178): the bootstrap Latte builds its shell from and
 * one table map. See MapReader for who sees what. A table map the caller can't
 * see answers 404, the same as an unmapped one.
 */
class MapReadController extends Controller
{
    public function __construct(private readonly MapReader $reader) {}

    public function show(Request $request, string $organizationId): JsonResponse
    {
        $attributes = $this->reader->bootstrap($this->isAdministrator($request));

        return $this->respond($request, 'maps', $attributes, $attributes['version']);
    }

    public function table(Request $request, string $organizationId, string $source, string $table): JsonResponse
    {
        try {
            if (! $this->isAdministrator($request)) {
                throw new MapNotFoundException('Not visible.');
            }
            $attributes = $this->reader->table($source, $table);
        } catch (MapNotFoundException) {
            $requestId = $this->requestId($request);

            return response()->json([
                'error' => [
                    'code' => 'resource_not_found',
                    'message' => 'The requested resource was not found.',
                    'request_id' => $requestId,
                ],
            ], Response::HTTP_NOT_FOUND)->header('X-Request-Id', $requestId);
        }

        return $this->respond($request, 'table_map', $attributes, $attributes['version']);
    }

    /** @param array<string, mixed> $attributes */
    private function respond(Request $request, string $type, array $attributes, int $version): JsonResponse
    {
        $requestId = $this->requestId($request);
        $etag = '"'.hash('sha256', $type.$version.json_encode($attributes, JSON_THROW_ON_ERROR)).'"';

        return response()->json([
            'data' => ['type' => $type, 'attributes' => $attributes],
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)->header('ETag', $etag)->header('Cache-Control', 'no-store');
    }

    private function isAdministrator(Request $request): bool
    {
        $membership = $request->attributes->get('pane_v1_membership');

        return $membership instanceof OrganizationMembership && $membership->isAdministrator();
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId) ? $requestId : (string) Str::uuid();
    }
}
