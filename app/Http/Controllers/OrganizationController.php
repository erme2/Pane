<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationTenancyService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationTenancyService $tenancy) {}

    public function list(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isPaneAdministrator()) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage organizations.', Response::HTTP_FORBIDDEN);
        }

        $page = $this->pageParameters($request);

        if ($page instanceof JsonResponse) {
            return $page;
        }

        $query = Organization::query()
            ->orderByDesc('created_at')
            ->orderByDesc('organization_id');

        if ($page['cursor'] !== null) {
            $cursorOrganization = $this->organizationFromCursor($page['cursor']);

            if (! $cursorOrganization instanceof Organization) {
                return $this->v1Error($request, 'invalid_cursor', 'The page cursor is invalid.', Response::HTTP_BAD_REQUEST);
            }

            $query->where(function ($query) use ($cursorOrganization): void {
                $query
                    ->where('created_at', '<', $cursorOrganization->created_at)
                    ->orWhere(function ($query) use ($cursorOrganization): void {
                        $query
                            ->where('created_at', $cursorOrganization->created_at)
                            ->where('organization_id', '<', $cursorOrganization->getKey());
                    });
            });
        }

        $organizations = $query->limit($page['limit'] + 1)->get();
        $hasMore = $organizations->count() > $page['limit'];
        $visibleOrganizations = $organizations->take($page['limit']);
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $visibleOrganizations
                ->map(fn (Organization $organization): array => $this->resource($organization))
                ->values(),
            'meta' => [
                'request_id' => $requestId,
                'page' => [
                    'next_cursor' => $hasMore ? $this->cursorForOrganization($visibleOrganizations->last()) : null,
                    'has_more' => $hasMore,
                ],
            ],
        ])->header('X-Request-Id', $requestId);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isPaneAdministrator()) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage organizations.', Response::HTTP_FORBIDDEN);
        }

        $attributes = $this->createAttributes($request);

        if ($attributes instanceof JsonResponse) {
            return $attributes;
        }

        try {
            $organization = $this->tenancy->createOrganization(
                $attributes['name'],
                $attributes['slug'],
                $attributes['database_limit'],
            );
        } catch (InvalidArgumentException $exception) {
            return $this->v1Error($request, 'validation_failed', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (QueryException $exception) {
            return $this->duplicateSlugError($request, $exception);
        }

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($organization),
            'meta' => ['request_id' => $requestId],
        ], Response::HTTP_CREATED)
            ->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($organization));
    }

    public function show(Request $request, string $organizationId): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isPaneAdministrator()) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage organizations.', Response::HTTP_FORBIDDEN);
        }

        $organization = $this->organizationFromId($request, $organizationId);

        if ($organization instanceof JsonResponse) {
            return $organization;
        }

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($organization),
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($organization));
    }

    public function update(Request $request, string $organizationId): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isPaneAdministrator()) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage organizations.', Response::HTTP_FORBIDDEN);
        }

        $organization = $this->organizationFromId($request, $organizationId);

        if ($organization instanceof JsonResponse) {
            return $organization;
        }

        $precondition = $this->ifMatchPrecondition($request, $organization);

        if ($precondition instanceof JsonResponse) {
            return $precondition;
        }

        $attributes = $this->updateAttributes($request);

        if ($attributes instanceof JsonResponse) {
            return $attributes;
        }

        try {
            $updated = $this->tenancy->updateOrganization($actor, $organization, $attributes, $this->expectedVersion($request));
        } catch (InvalidArgumentException $exception) {
            return $this->v1Error($request, 'validation_failed', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (QueryException $exception) {
            return $this->duplicateSlugError($request, $exception);
        } catch (DomainException $exception) {
            if ($exception->getMessage() === OrganizationTenancyService::VERSION_CONFLICT_MESSAGE) {
                return $this->v1Error($request, 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
            }

            return $this->v1Error($request, 'permission_denied', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($updated),
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($updated));
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function resource(Organization $organization): array
    {
        return [
            'id' => (string) $organization->getKey(),
            'type' => 'organization',
            'attributes' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status,
                'database_limit' => $organization->database_limit,
                'active_database_connections' => $organization->active_database_connections,
                'over_database_limit' => $organization->isOverDatabaseLimit(),
                'created_at' => $organization->created_at?->toJSON(),
                'updated_at' => $organization->updated_at?->toJSON(),
            ],
        ];
    }

    /**
     * @return array{name: string, slug: string, database_limit: int}|JsonResponse
     */
    private function createAttributes(Request $request): array|JsonResponse
    {
        $body = $this->requestBody($request);
        $unsupportedFields = array_values(array_diff(array_keys($body), ['name', 'slug', 'database_limit']));

        if ($unsupportedFields !== []) {
            sort($unsupportedFields);

            return $this->v1Error($request, 'validation_failed', 'The '.$unsupportedFields[0].' field is not supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validator = Validator::make($body, [
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'database_limit' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->v1Error($request, 'validation_failed', 'The organization payload is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return [
            'name' => trim((string) $body['name']),
            'slug' => (string) $body['slug'],
            'database_limit' => (int) $body['database_limit'],
        ];
    }

    /**
     * @return array{name?: string, slug?: string, status?: string, database_limit?: int}|JsonResponse
     */
    private function updateAttributes(Request $request): array|JsonResponse
    {
        $body = $this->requestBody($request);
        $unsupportedFields = array_values(array_diff(array_keys($body), ['name', 'slug', 'status', 'database_limit']));

        if ($unsupportedFields !== []) {
            sort($unsupportedFields);

            return $this->v1Error($request, 'validation_failed', 'The '.$unsupportedFields[0].' field is not supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($body === []) {
            return $this->v1Error($request, 'validation_failed', 'At least one organization field is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validator = Validator::make($body, [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:200'],
            'slug' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'status' => ['sometimes', 'required', 'in:'.implode(',', Organization::STATUSES)],
            'database_limit' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->v1Error($request, 'validation_failed', 'The organization payload is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attributes = [];

        foreach (['name', 'slug', 'status', 'database_limit'] as $field) {
            if (array_key_exists($field, $body)) {
                $attributes[$field] = $field === 'database_limit' ? (int) $body[$field] : (string) $body[$field];
            }
        }

        return $attributes;
    }

    private function organizationFromId(Request $request, string $organizationId): Organization|JsonResponse
    {
        if (! Str::isUuid($organizationId)) {
            return $this->v1Error($request, 'invalid_identifier', 'The organization_id path parameter must be a valid UUID.', Response::HTTP_BAD_REQUEST);
        }

        $organization = Organization::query()->find($organizationId);

        return $organization instanceof Organization
            ? $organization
            : $this->v1Error($request, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
    }

    private function duplicateSlugError(Request $request, QueryException $exception): JsonResponse
    {
        if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
            return $this->v1Error($request, 'duplicate_resource', 'Organization slug is already registered.', Response::HTTP_CONFLICT);
        }

        throw $exception;
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

    private function etag(Organization $organization): string
    {
        return '"'.$organization->versionTag().'"';
    }

    /**
     * @return array{limit: int, cursor: string|null}|JsonResponse
     */
    private function pageParameters(Request $request): array|JsonResponse
    {
        $page = $request->query('page', []);

        if (! is_array($page)) {
            return $this->v1Error($request, 'invalid_request', 'The page parameter must be an object.', Response::HTTP_BAD_REQUEST);
        }

        $limit = $page['limit'] ?? 25;

        if (is_string($limit) && ctype_digit($limit)) {
            $limit = (int) $limit;
        }

        if (! is_int($limit) || $limit < 1 || $limit > 100) {
            return $this->v1Error($request, 'invalid_request', 'The page limit must be between 1 and 100.', Response::HTTP_BAD_REQUEST);
        }

        $cursor = $page['cursor'] ?? null;

        if ($cursor !== null && (! is_string($cursor) || $cursor === '')) {
            return $this->v1Error($request, 'invalid_cursor', 'The page cursor is invalid.', Response::HTTP_BAD_REQUEST);
        }

        return [
            'limit' => $limit,
            'cursor' => $cursor,
        ];
    }

    private function cursorForOrganization(?Organization $organization): ?string
    {
        if (! $organization instanceof Organization) {
            return null;
        }

        return rtrim(strtr(base64_encode(json_encode([
            'v' => 1,
            'id' => (string) $organization->getKey(),
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function organizationFromCursor(string $cursor): ?Organization
    {
        if (! preg_match('/\A[A-Za-z0-9_-]{1,2048}\z/', $cursor)) {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (! is_string($decoded)) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            ! is_array($payload)
            || ($payload['v'] ?? null) !== 1
            || ! is_string($payload['id'] ?? null)
            || ! Str::isUuid($payload['id'])
        ) {
            return null;
        }

        return Organization::query()->find($payload['id']);
    }

    private function ifMatchPrecondition(Request $request, Organization $organization): ?JsonResponse
    {
        $ifMatch = $request->header('If-Match');

        if (! is_string($ifMatch) || $ifMatch === '') {
            return $this->v1Error($request, 'precondition_required', 'The If-Match header is required for this operation.', Response::HTTP_PRECONDITION_REQUIRED);
        }

        if (! preg_match('/^"[A-Za-z0-9._~-]{1,128}"$/', $ifMatch)) {
            return $this->v1Error($request, 'invalid_request', 'The If-Match header must be a quoted strong ETag.', Response::HTTP_BAD_REQUEST);
        }

        if (! hash_equals($this->etag($organization), $ifMatch)) {
            return $this->v1Error($request, 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
        }

        return null;
    }

    private function expectedVersion(Request $request): string
    {
        return trim((string) $request->header('If-Match'), '"');
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(Request $request): array
    {
        return $request->isJson()
            ? $request->json()->all()
            : $request->request->all();
    }
}
