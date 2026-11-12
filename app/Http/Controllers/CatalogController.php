<?php

namespace App\Http\Controllers;

use App\Models\ApplicationRegistration;
use App\Models\CatalogObject;
use App\Models\CatalogRefresh;
use App\Models\ManagedConnection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\CatalogService;
use App\Support\ManagedDatabase\CatalogUnavailableException;
use App\Support\ManagedDatabase\CatalogVersionChangedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use JsonException;

/**
 * v1 API for the discovered catalog of a managed connection: start a refresh,
 * read discovered objects, and edit their descriptions (If-Match required).
 * Organization administrators only until connection grants exist.
 */
class CatalogController extends Controller
{
    private const int REFRESHES_PER_MINUTE = 3;

    private const int DESCRIPTION_MAX_LENGTH = 5000;

    public function __construct(private readonly CatalogService $catalog) {}

    public function refresh(Request $request, string $organizationId, string $connectionId): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $connection = $this->connection($request, $context['organization'], $connectionId);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $allowed = RateLimiter::attempt('catalog-refresh:'.$connection->getKey(), self::REFRESHES_PER_MINUTE, fn (): bool => true);
        if (! $allowed) {
            return $this->v1Error($request, 'rate_limited', 'Too many catalog refreshes. Wait a minute, then try again.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $refresh = $this->catalog->refresh($connection, $context['actor'], $this->requestId($request));
        } catch (CatalogUnavailableException $exception) {
            return $this->v1Error($request, 'dependency_unavailable', $exception->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->refreshResource($refresh),
            'meta' => ['request_id' => $requestId],
        ], Response::HTTP_ACCEPTED)->header('X-Request-Id', $requestId);
    }

    public function list(Request $request, string $organizationId, string $connectionId): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $connection = $this->connection($request, $context['organization'], $connectionId);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $page = $this->pageParameters($request);
        if ($page instanceof JsonResponse) {
            return $page;
        }

        $query = CatalogObject::query()
            ->where('connection_id', $connection->getKey())
            ->orderBy('sort_rank')
            ->orderBy('name')
            ->orderBy('catalog_object_id');

        if ($page['cursor'] !== null) {
            $cursor = $this->objectFromCursor($page['cursor'], $connection);
            if (! $cursor instanceof CatalogObject) {
                return $this->v1Error($request, 'invalid_cursor', 'The page cursor is invalid.', Response::HTTP_BAD_REQUEST);
            }
            $query->where(fn ($query) => $query
                ->where('sort_rank', '>', $cursor->sort_rank)
                ->orWhere(fn ($query) => $query->where('sort_rank', $cursor->sort_rank)->where('name', '>', $cursor->name))
                ->orWhere(fn ($query) => $query->where('sort_rank', $cursor->sort_rank)->where('name', $cursor->name)->where('catalog_object_id', '>', $cursor->getKey())));
        }

        $rows = $query->limit($page['limit'] + 1)->get();
        $hasMore = $rows->count() > $page['limit'];
        $visible = $rows->take($page['limit']);
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $visible->map(fn (CatalogObject $object): array => $this->resource($object))->values(),
            'meta' => [
                'request_id' => $requestId,
                'page' => ['next_cursor' => $hasMore ? $this->cursorFor($visible->last()) : null, 'has_more' => $hasMore],
            ],
        ])->header('X-Request-Id', $requestId);
    }

    public function show(Request $request, string $organizationId, string $connectionId, string $catalogObjectId): JsonResponse
    {
        $object = $this->resolveObject($request, $connectionId, $catalogObjectId);

        return $object instanceof JsonResponse ? $object : $this->single($request, $object['object']);
    }

    public function updateDescription(Request $request, string $organizationId, string $connectionId, string $catalogObjectId): JsonResponse
    {
        $resolved = $this->resolveObject($request, $connectionId, $catalogObjectId);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        $object = $resolved['object'];

        $precondition = $this->ifMatch($request, $object);
        if ($precondition instanceof JsonResponse) {
            return $precondition;
        }

        $body = $request->all();
        $unsupported = array_values(array_diff(array_keys($body), ['description']));
        if ($unsupported !== []) {
            sort($unsupported);

            return $this->v1Error($request, 'validation_failed', 'The '.$unsupported[0].' field is not supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! array_key_exists('description', $body) || ($body['description'] !== null && ! is_string($body['description']))) {
            return $this->v1Error($request, 'validation_failed', 'The description field is required and must be a string or null.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (is_string($body['description']) && mb_strlen($body['description']) > self::DESCRIPTION_MAX_LENGTH) {
            return $this->v1Error($request, 'validation_failed', 'The description may not be longer than '.self::DESCRIPTION_MAX_LENGTH.' characters.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $updated = $this->catalog->updateDescription($object, $resolved['actor'], $body['description'], trim((string) $request->header('If-Match'), '"'), $this->requestId($request));
        } catch (CatalogVersionChangedException) {
            return $this->versionConflict($request);
        }

        return $this->single($request, $updated);
    }

    /**
     * @return array{actor: User, object: CatalogObject}|JsonResponse
     */
    private function resolveObject(Request $request, string $connectionId, string $catalogObjectId): array|JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $connection = $this->connection($request, $context['organization'], $connectionId);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        if (! Str::isUuid($catalogObjectId)) {
            return $this->v1Error($request, 'invalid_identifier', 'The catalog_object_id path parameter must be a valid UUID.', Response::HTTP_BAD_REQUEST);
        }

        $object = CatalogObject::query()
            ->where('connection_id', $connection->getKey())
            ->whereKey($catalogObjectId)
            ->first();

        if (! $object instanceof CatalogObject) {
            return $this->v1Error($request, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
        }

        return ['actor' => $context['actor'], 'object' => $object];
    }

    private function single(Request $request, CatalogObject $object): JsonResponse
    {
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($object),
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)
            ->header('ETag', '"'.$object->versionTag().'"');
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function resource(CatalogObject $object): array
    {
        return [
            'id' => (string) $object->getKey(),
            'type' => 'catalog_object',
            'attributes' => [
                'connection_id' => $object->connection_id,
                'kind' => $object->kind,
                'name' => $object->name,
                'status' => $object->status,
                'description' => $object->description,
                'created_at' => $object->created_at?->toJSON(),
                'updated_at' => $object->updated_at?->toJSON(),
            ],
        ];
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function refreshResource(CatalogRefresh $refresh): array
    {
        return [
            'id' => (string) $refresh->getKey(),
            'type' => 'catalog_refresh',
            'attributes' => [
                'connection_id' => $refresh->connection_id,
                'status' => $refresh->status,
                'created_at' => $refresh->created_at?->toJSON(),
            ],
        ];
    }

    /**
     * @return array{actor: User, organization: Organization}|JsonResponse
     */
    private function context(Request $request): array|JsonResponse
    {
        $actor = $request->user();
        $application = $request->attributes->get('pane_v1_application');
        $organization = $request->attributes->get('pane_v1_organization');
        $membership = $request->attributes->get('pane_v1_membership');

        if (
            ! $actor instanceof User
            || ! $application instanceof ApplicationRegistration
            || ! $organization instanceof Organization
            || ! $membership instanceof OrganizationMembership
            || ! $membership->isAdministrator()
        ) {
            return $this->v1Error($request, 'permission_denied', 'Only active organization administrators can manage database catalogs.', Response::HTTP_FORBIDDEN);
        }

        return ['actor' => $actor, 'organization' => $organization];
    }

    private function connection(Request $request, Organization $organization, string $connectionId): ManagedConnection|JsonResponse
    {
        if (! Str::isUuid($connectionId)) {
            return $this->v1Error($request, 'invalid_identifier', 'The connection_id path parameter must be a valid UUID.', Response::HTTP_BAD_REQUEST);
        }

        $connection = ManagedConnection::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($connectionId)
            ->first();

        return $connection instanceof ManagedConnection
            ? $connection
            : $this->v1Error($request, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
    }

    private function ifMatch(Request $request, CatalogObject $object): ?JsonResponse
    {
        $ifMatch = $request->header('If-Match');

        if ($ifMatch === null || $ifMatch === '') {
            return $this->v1Error($request, 'precondition_required', 'The If-Match header is required for this operation.', Response::HTTP_PRECONDITION_REQUIRED);
        }

        if (! preg_match('/\A"[A-Za-z0-9._~-]{1,128}"\z/', $ifMatch)) {
            return $this->v1Error($request, 'invalid_request', 'The If-Match header must be a quoted strong ETag.', Response::HTTP_BAD_REQUEST);
        }

        if (! hash_equals('"'.$object->versionTag().'"', $ifMatch)) {
            return $this->versionConflict($request);
        }

        return null;
    }

    private function versionConflict(Request $request): JsonResponse
    {
        return $this->v1Error($request, 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
    }

    private function v1Error(Request $request, string $code, string $message, int $status): JsonResponse
    {
        $requestId = $this->requestId($request);

        return response()->json([
            'error' => ['code' => $code, 'message' => $message, 'request_id' => $requestId],
        ], $status)->header('X-Request-Id', $requestId);
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-Id');

        if (! is_string($requestId) || ! Str::isUuid($requestId)) {
            $requestId = $request->attributes->get('pane_catalog_request_id');
            if (! is_string($requestId)) {
                $requestId = (string) Str::uuid();
                $request->attributes->set('pane_catalog_request_id', $requestId);
            }
        }

        return $requestId;
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

        return ['limit' => $limit, 'cursor' => $cursor];
    }

    private function cursorFor(?CatalogObject $object): ?string
    {
        if (! $object instanceof CatalogObject) {
            return null;
        }

        return rtrim(strtr(base64_encode(json_encode(['v' => 1, 'id' => (string) $object->getKey()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function objectFromCursor(string $cursor, ManagedConnection $connection): ?CatalogObject
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

        if (! is_array($payload) || ($payload['v'] ?? null) !== 1 || ! is_string($payload['id'] ?? null) || ! Str::isUuid($payload['id'])) {
            return null;
        }

        $object = CatalogObject::query()->where('connection_id', $connection->getKey())->find($payload['id']);

        return $object instanceof CatalogObject ? $object : null;
    }
}
