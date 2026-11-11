<?php

namespace App\Http\Controllers;

use App\Models\ApplicationRegistration;
use App\Models\ManagedConnection;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ManagedConnectionService;
use App\Support\ManagedDatabase\ConnectionRejectedException;
use App\Support\ManagedDatabase\ConnectionVersionChangedException;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;

/**
 * v1 API for an organization's managed database connections. Organization
 * administrators only. Credentials are write-only: accepted on create and
 * update, never returned (only `credentials_configured`).
 */
class ManagedConnectionController extends Controller
{
    private const array PROFILE_FIELDS = ['engine', 'name', 'description', 'host', 'port', 'database', 'username', 'tls_mode', 'status'];

    private const array SECRET_FIELDS = ['password', 'ca_certificate', 'client_certificate', 'client_private_key'];

    private const int TESTS_PER_MINUTE = 5;

    public function __construct(private readonly ManagedConnectionService $connections) {}

    public function list(Request $request, string $organizationId): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $page = $this->pageParameters($request);
        if ($page instanceof JsonResponse) {
            return $page;
        }

        $organization = $context['organization'];
        $query = ManagedConnection::query()
            ->where('organization_id', $organization->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('connection_id');

        if ($page['cursor'] !== null) {
            $cursor = $this->connectionFromCursor($page['cursor'], $organization);
            if (! $cursor instanceof ManagedConnection) {
                return $this->v1Error($request, 'invalid_cursor', 'The page cursor is invalid.', Response::HTTP_BAD_REQUEST);
            }
            $query->where(fn ($query) => $query
                ->where('created_at', '<', $cursor->created_at)
                ->orWhere(fn ($query) => $query->where('created_at', $cursor->created_at)->where('connection_id', '<', $cursor->getKey())));
        }

        $rows = $query->limit($page['limit'] + 1)->get();
        $hasMore = $rows->count() > $page['limit'];
        $visible = $rows->take($page['limit']);
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $visible->map(fn (ManagedConnection $connection): array => $this->resource($connection))->values(),
            'meta' => [
                'request_id' => $requestId,
                'page' => ['next_cursor' => $hasMore ? $this->cursorFor($visible->last()) : null, 'has_more' => $hasMore],
            ],
        ])->header('X-Request-Id', $requestId);
    }

    public function store(Request $request, string $organizationId): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $input = $this->validated($request, creating: true);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        try {
            $connection = $this->connections->create($context['organization'], $context['actor'], $input['profile'], $input['secrets'] ?? [], $this->requestId($request));
        } catch (ConnectionRejectedException $exception) {
            return $this->v1Error($request, 'connection_policy_rejected', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DomainException $exception) {
            return $this->conflict($request, $exception);
        }

        return $this->single($request, $connection, Response::HTTP_CREATED);
    }

    public function show(Request $request, string $organizationId, string $connectionId): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $connection = $this->scoped($request, $context['organization'], $connectionId);

        return $connection instanceof JsonResponse ? $connection : $this->single($request, $connection);
    }

    public function update(Request $request, string $organizationId, string $connectionId): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $connection = $this->scoped($request, $context['organization'], $connectionId);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $precondition = $this->ifMatch($request, $connection);
        if ($precondition instanceof JsonResponse) {
            return $precondition;
        }

        $input = $this->validated($request, creating: false);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        try {
            $updated = $this->connections->update($connection, $context['actor'], $input['profile'], $input['secrets'], $this->expectedVersion($request), $this->requestId($request));
        } catch (ConnectionRejectedException $exception) {
            return $this->v1Error($request, 'connection_policy_rejected', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ConnectionVersionChangedException) {
            return $this->versionConflict($request);
        } catch (DomainException $exception) {
            return $this->conflict($request, $exception);
        }

        return $this->single($request, $updated);
    }

    public function destroy(Request $request, string $organizationId, string $connectionId): Response|JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $connection = $this->scoped($request, $context['organization'], $connectionId);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $precondition = $this->ifMatch($request, $connection);
        if ($precondition instanceof JsonResponse) {
            return $precondition;
        }

        try {
            $this->connections->disable($connection, $context['actor'], $this->expectedVersion($request), $this->requestId($request));
        } catch (ConnectionVersionChangedException) {
            return $this->versionConflict($request);
        }

        return response()->noContent()->header('X-Request-Id', $this->requestId($request));
    }

    public function test(Request $request, string $organizationId, string $connectionId): JsonResponse
    {
        $context = $this->context($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $connection = $this->scoped($request, $context['organization'], $connectionId);
        if ($connection instanceof JsonResponse) {
            return $connection;
        }

        $allowed = RateLimiter::attempt('managed-connection-test:'.$connection->getKey(), self::TESTS_PER_MINUTE, fn (): bool => true);
        if (! $allowed) {
            return $this->v1Error($request, 'rate_limited', 'Too many connection tests. Wait a minute, then try again.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $result = $this->connections->test($connection, $context['actor'], $this->requestId($request));

        if (! $result['privileges_valid']) {
            return $this->v1Error($request, 'connection_test_failed', (string) $result['message'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => [
                'id' => (string) Str::uuid(),
                'type' => 'connection_test',
                'attributes' => [
                    'connection_id' => $connection->getKey(),
                    'reachable' => $result['reachable'],
                    'privileges_valid' => $result['privileges_valid'],
                    'tested_at' => $result['tested_at'],
                ],
            ],
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId);
    }

    /**
     * @return array{profile: array<string, mixed>, secrets: array<string, string>|null}|JsonResponse
     */
    private function validated(Request $request, bool $creating): array|JsonResponse
    {
        $body = $request->all();
        $unsupported = array_values(array_diff(array_keys($body), [...self::PROFILE_FIELDS, 'credentials']));
        if ($unsupported !== []) {
            sort($unsupported);

            return $this->v1Error($request, 'validation_failed', 'The '.$unsupported[0].' field is not supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $credentials = $body['credentials'] ?? null;
        if (is_array($credentials) && array_diff(array_keys($credentials), self::SECRET_FIELDS) !== []) {
            return $this->v1Error($request, 'validation_failed', 'The credentials object has an unsupported field.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $required = $creating ? 'required' : 'sometimes';
        $validator = Validator::make($body, [
            'engine' => [$required, 'string', 'in:'.implode(',', ManagedConnection::ENGINES)],
            'name' => [$required, 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'host' => [$required, 'string', 'min:1', 'max:253'],
            'port' => [$required, 'integer', 'between:1,65535'],
            'database' => [$required, 'string', 'min:1', 'max:64'],
            'username' => [$required, 'string', 'min:1', 'max:128'],
            'tls_mode' => [$required, 'string', 'in:'.implode(',', ManagedConnection::TLS_MODES)],
            'status' => ['sometimes', 'string', 'in:'.implode(',', ManagedConnection::STATUSES)],
            'credentials' => [$creating ? 'required' : 'sometimes', 'array'],
            'credentials.password' => [$creating ? 'required' : 'sometimes', 'string', 'min:1'],
            'credentials.ca_certificate' => ['sometimes', 'string'],
            'credentials.client_certificate' => ['sometimes', 'string'],
            'credentials.client_private_key' => ['sometimes', 'string'],
        ]);

        if ($validator->fails() || (! $creating && $body === [])) {
            $message = $validator->errors()->first() ?: 'At least one supported field is required.';

            // Never echo submitted values back: messages name fields only.
            return $this->v1Error($request, 'validation_failed', (string) $message, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $profile = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $body)) {
                $profile[$field === 'database' ? 'database_name' : $field] = $field === 'port' ? (int) $body[$field] : $body[$field];
            }
        }

        $secrets = is_array($credentials) ? array_filter($credentials, 'is_string') : null;

        if (! $creating && $secrets !== null && ! isset($secrets['password'])) {
            return $this->v1Error($request, 'validation_failed', 'Setting new credentials requires the password.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ['profile' => $profile, 'secrets' => $secrets];
    }

    private function conflict(Request $request, DomainException $exception): JsonResponse
    {
        $code = str_contains($exception->getMessage(), 'quota') ? 'quota_exceeded'
            : (str_contains($exception->getMessage(), 'already exists') ? 'duplicate_resource' : 'operation_conflict');

        return $this->v1Error($request, $code, $exception->getMessage(), Response::HTTP_CONFLICT);
    }

    private function single(Request $request, ManagedConnection $connection, int $status = Response::HTTP_OK): JsonResponse
    {
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($connection),
            'meta' => ['request_id' => $requestId],
        ], $status)->header('X-Request-Id', $requestId)
            ->header('ETag', '"'.$connection->versionTag().'"');
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function resource(ManagedConnection $connection): array
    {
        return [
            'id' => (string) $connection->getKey(),
            'type' => 'connection',
            'attributes' => [
                'engine' => $connection->engine,
                'name' => $connection->name,
                'description' => $connection->description,
                'host' => $connection->host,
                'port' => $connection->port,
                'database' => $connection->database_name,
                'username' => $connection->username,
                'tls_mode' => $connection->tls_mode,
                'status' => $connection->status,
                'credentials_configured' => $connection->managed_credential_secret_id !== null,
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
            return $this->v1Error($request, 'permission_denied', 'Only active organization administrators can manage database connections.', Response::HTTP_FORBIDDEN);
        }

        return ['actor' => $actor, 'organization' => $organization];
    }

    private function scoped(Request $request, Organization $organization, string $connectionId): ManagedConnection|JsonResponse
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

    private function ifMatch(Request $request, ManagedConnection $connection): ?JsonResponse
    {
        $ifMatch = $request->header('If-Match');

        if ($ifMatch === null || $ifMatch === '') {
            return $this->v1Error($request, 'precondition_required', 'The If-Match header is required for this operation.', Response::HTTP_PRECONDITION_REQUIRED);
        }

        if (! preg_match('/\A"[A-Za-z0-9._~-]{1,128}"\z/', $ifMatch)) {
            return $this->v1Error($request, 'invalid_request', 'The If-Match header must be a quoted strong ETag.', Response::HTTP_BAD_REQUEST);
        }

        if (! hash_equals('"'.$connection->versionTag().'"', $ifMatch)) {
            return $this->versionConflict($request);
        }

        return null;
    }

    /** The version tag from a validated If-Match header (see ifMatch). */
    private function expectedVersion(Request $request): string
    {
        return trim((string) $request->header('If-Match'), '"');
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
            $requestId = $request->attributes->get('pane_connection_request_id');
            if (! is_string($requestId)) {
                $requestId = (string) Str::uuid();
                $request->attributes->set('pane_connection_request_id', $requestId);
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

    private function cursorFor(?ManagedConnection $connection): ?string
    {
        if (! $connection instanceof ManagedConnection) {
            return null;
        }

        return rtrim(strtr(base64_encode(json_encode(['v' => 1, 'id' => (string) $connection->getKey()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function connectionFromCursor(string $cursor, Organization $organization): ?ManagedConnection
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

        $connection = ManagedConnection::query()->where('organization_id', $organization->getKey())->find($payload['id']);

        return $connection instanceof ManagedConnection ? $connection : null;
    }
}
