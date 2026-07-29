<?php

namespace App\Http\Controllers;

use App\Models\ApplicationRegistration;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Support\LatteApplicationConfig;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ApplicationController extends Controller
{
    public function __construct(private readonly ApplicationRegistryService $applications) {}

    public function list(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isPaneAdministrator()) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage applications.', Response::HTTP_FORBIDDEN);
        }

        $page = $this->pageParameters($request);

        if ($page instanceof JsonResponse) {
            return $page;
        }

        $query = ApplicationRegistration::query()
            ->orderByDesc('created_at')
            ->orderByDesc('application_id');

        if ($page['cursor'] !== null) {
            $cursorApplication = $this->applicationFromCursor($page['cursor']);

            if (! $cursorApplication instanceof ApplicationRegistration) {
                return $this->v1Error($request, 'invalid_cursor', 'The page cursor is invalid.', Response::HTTP_BAD_REQUEST);
            }

            $query->where(function ($query) use ($cursorApplication): void {
                $query
                    ->where('created_at', '<', $cursorApplication->created_at)
                    ->orWhere(function ($query) use ($cursorApplication): void {
                        $query
                            ->where('created_at', $cursorApplication->created_at)
                            ->where('application_id', '<', $cursorApplication->getKey());
                    });
            });
        }

        $applications = $query->limit($page['limit'] + 1)->get();
        $hasMore = $applications->count() > $page['limit'];
        $visibleApplications = $applications->take($page['limit']);
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $visibleApplications->map(fn (ApplicationRegistration $application): array => $this->resource($application))->values(),
            'meta' => [
                'request_id' => $requestId,
                'page' => [
                    'next_cursor' => $hasMore ? $this->cursorForApplication($visibleApplications->last()) : null,
                    'has_more' => $hasMore,
                ],
            ],
        ])->header('X-Request-Id', $requestId);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage applications.', Response::HTTP_FORBIDDEN);
        }

        $attributes = $this->createAttributes($request);

        if ($attributes instanceof JsonResponse) {
            return $attributes;
        }

        try {
            $application = $this->applications->create($actor, $attributes);
        } catch (DomainException $exception) {
            return $this->domainError($request, $exception);
        }

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($application),
            'meta' => ['request_id' => $requestId],
        ], Response::HTTP_CREATED)
            ->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($application));
    }

    public function show(Request $request, string $applicationId): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isPaneAdministrator()) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage applications.', Response::HTTP_FORBIDDEN);
        }

        $application = $this->applicationFromId($request, $applicationId);

        if ($application instanceof JsonResponse) {
            return $application;
        }

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($application),
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($application));
    }

    public function update(Request $request, string $applicationId): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage applications.', Response::HTTP_FORBIDDEN);
        }

        $application = $this->applicationFromId($request, $applicationId);

        if ($application instanceof JsonResponse) {
            return $application;
        }

        $precondition = $this->ifMatchPrecondition($request, $application);

        if ($precondition instanceof JsonResponse) {
            return $precondition;
        }

        $attributes = $this->updateAttributes($request);

        if ($attributes instanceof JsonResponse) {
            return $attributes;
        }

        try {
            $updated = $this->applications->update($actor, $application, $attributes);
        } catch (DomainException $exception) {
            return $this->domainError($request, $exception);
        }

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($updated),
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($updated));
    }

    public function destroy(Request $request, string $applicationId): Response|JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage applications.', Response::HTTP_FORBIDDEN);
        }

        $application = $this->applicationFromId($request, $applicationId);

        if ($application instanceof JsonResponse) {
            return $application;
        }

        $precondition = $this->ifMatchPrecondition($request, $application);

        if ($precondition instanceof JsonResponse) {
            return $precondition;
        }

        try {
            $this->applications->disable($actor, $application);
        } catch (DomainException $exception) {
            return $this->domainError($request, $exception);
        }

        return response()->noContent()
            ->header('X-Request-Id', $this->requestId($request));
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function resource(ApplicationRegistration $application): array
    {
        return [
            'id' => (string) $application->getKey(),
            'type' => 'application',
            'attributes' => [
                'name' => $application->name,
                'kind' => $application->kind,
                'organization_id' => $application->isLatte() ? $application->organization_id : null,
                'trusted_origin' => $application->trusted_origin,
                'redirect_uris' => array_values($application->redirect_uris ?? []),
                'status' => $application->status,
                'created_at' => $application->created_at?->toJSON(),
                'updated_at' => $application->updated_at?->toJSON(),
            ],
        ];
    }

    /**
     * @return array{name: string, kind: string, trusted_origin: string, redirect_uris: array<int, string>, organization_id?: string|null}|JsonResponse
     */
    private function createAttributes(Request $request): array|JsonResponse
    {
        $body = $this->requestBody($request);
        $unsupportedFields = array_values(array_diff(array_keys($body), ['name', 'kind', 'organization_id', 'trusted_origin', 'redirect_uris']));

        if ($unsupportedFields !== []) {
            sort($unsupportedFields);

            return $this->v1Error($request, 'validation_failed', 'The '.$unsupportedFields[0].' field is not supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validator = Validator::make($body, [
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'kind' => ['required', 'in:latte,burro'],
            'organization_id' => ['nullable', 'uuid'],
            'trusted_origin' => ['required', 'string', 'max:2048'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => ['required', 'string', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return $this->v1Error($request, 'validation_failed', 'The application payload is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $kind = (string) $body['kind'];
        $organizationId = $body['organization_id'] ?? null;

        if ($kind === ApplicationRegistration::KIND_LATTE && ! is_string($organizationId)) {
            return $this->v1Error($request, 'validation_failed', 'The organization_id field is required for latte applications.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($kind === ApplicationRegistration::KIND_BURRO && $organizationId !== null) {
            return $this->v1Error($request, 'validation_failed', 'The organization_id field is not supported for burro applications.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($kind === ApplicationRegistration::KIND_LATTE && ! Organization::query()->whereKey($organizationId)->exists()) {
            return $this->v1Error($request, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
        }

        $trustedOrigin = LatteApplicationConfig::normalizeOrigin((string) $body['trusted_origin']);

        if ($trustedOrigin === null) {
            return $this->v1Error($request, 'validation_failed', 'The trusted_origin field must be a valid trusted origin.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $redirectUris = $this->normalizedRedirectUris($request, $body['redirect_uris']);

        if ($redirectUris instanceof JsonResponse) {
            return $redirectUris;
        }

        return [
            'name' => trim((string) $body['name']),
            'kind' => $kind,
            'organization_id' => $organizationId,
            'trusted_origin' => $trustedOrigin,
            'redirect_uris' => $redirectUris,
        ];
    }

    /**
     * @return array{name?: string, trusted_origin?: string, redirect_uris?: array<int, string>, status?: string}|JsonResponse
     */
    private function updateAttributes(Request $request): array|JsonResponse
    {
        $body = $this->requestBody($request);
        $unsupportedFields = array_values(array_diff(array_keys($body), ['name', 'trusted_origin', 'redirect_uris', 'status']));

        if ($unsupportedFields !== []) {
            sort($unsupportedFields);

            return $this->v1Error($request, 'validation_failed', 'The '.$unsupportedFields[0].' field is not supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($body === []) {
            return $this->v1Error($request, 'validation_failed', 'At least one application field is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validator = Validator::make($body, [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:200'],
            'trusted_origin' => ['sometimes', 'required', 'string', 'max:2048'],
            'redirect_uris' => ['sometimes', 'required', 'array', 'min:1'],
            'redirect_uris.*' => ['required', 'string', 'max:2048'],
            'status' => ['sometimes', 'required', 'in:active,disabled'],
        ]);

        if ($validator->fails()) {
            return $this->v1Error($request, 'validation_failed', 'The application payload is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attributes = [];

        if (array_key_exists('name', $body)) {
            $attributes['name'] = trim((string) $body['name']);
        }

        if (array_key_exists('trusted_origin', $body)) {
            $trustedOrigin = LatteApplicationConfig::normalizeOrigin((string) $body['trusted_origin']);

            if ($trustedOrigin === null) {
                return $this->v1Error($request, 'validation_failed', 'The trusted_origin field must be a valid trusted origin.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $attributes['trusted_origin'] = $trustedOrigin;
        }

        if (array_key_exists('redirect_uris', $body)) {
            $redirectUris = $this->normalizedRedirectUris($request, $body['redirect_uris']);

            if ($redirectUris instanceof JsonResponse) {
                return $redirectUris;
            }

            $attributes['redirect_uris'] = $redirectUris;
        }

        if (array_key_exists('status', $body)) {
            $attributes['status'] = (string) $body['status'];
        }

        return $attributes;
    }

    /**
     * @return array<int, string>|JsonResponse
     */
    private function normalizedRedirectUris(Request $request, mixed $redirectUris): array|JsonResponse
    {
        if (! is_array($redirectUris)) {
            return $this->v1Error($request, 'validation_failed', 'The redirect_uris field must be an array.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $normalized = [];

        foreach ($redirectUris as $redirectUri) {
            if (! is_string($redirectUri)) {
                return $this->v1Error($request, 'validation_failed', 'Every redirect_uris entry must be a valid redirect URI.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $redirectUri = LatteApplicationConfig::normalizeRedirectUri($redirectUri);

            if ($redirectUri === null) {
                return $this->v1Error($request, 'validation_failed', 'Every redirect_uris entry must be a valid redirect URI.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $normalized[] = $redirectUri;
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized === []
            ? $this->v1Error($request, 'validation_failed', 'The redirect_uris field requires at least one value.', Response::HTTP_UNPROCESSABLE_ENTITY)
            : $normalized;
    }

    private function applicationFromId(Request $request, string $applicationId): ApplicationRegistration|JsonResponse
    {
        if (! Str::isUuid($applicationId)) {
            return $this->v1Error($request, 'invalid_identifier', 'The application_id path parameter must be a valid UUID.', Response::HTTP_BAD_REQUEST);
        }

        $application = ApplicationRegistration::query()->find($applicationId);

        return $application instanceof ApplicationRegistration
            ? $application
            : $this->v1Error($request, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
    }

    private function domainError(Request $request, DomainException $exception): JsonResponse
    {
        return str_contains($exception->getMessage(), 'already registered')
            ? $this->v1Error($request, 'duplicate_resource', $exception->getMessage(), Response::HTTP_CONFLICT)
            : $this->v1Error($request, 'permission_denied', $exception->getMessage(), Response::HTTP_FORBIDDEN);
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

    private function etag(ApplicationRegistration $application): string
    {
        return '"'.hash('sha256', implode('|', [
            $application->getKey(),
            $application->status,
            $application->trusted_origin,
            json_encode($application->redirect_uris, JSON_THROW_ON_ERROR),
            $application->updated_at?->toJSON(),
        ])).'"';
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

    private function cursorForApplication(?ApplicationRegistration $application): ?string
    {
        if (! $application instanceof ApplicationRegistration) {
            return null;
        }

        return rtrim(strtr(base64_encode(json_encode([
            'v' => 1,
            'id' => $application->getKey(),
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function applicationFromCursor(string $cursor): ?ApplicationRegistration
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (! is_string($decoded)) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
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

        return ApplicationRegistration::query()->find($payload['id']);
    }

    private function ifMatchPrecondition(Request $request, ApplicationRegistration $application): ?JsonResponse
    {
        $ifMatch = $request->header('If-Match');

        if (! is_string($ifMatch) || $ifMatch === '') {
            return $this->v1Error($request, 'precondition_required', 'The If-Match header is required for this operation.', Response::HTTP_PRECONDITION_REQUIRED);
        }

        if (! preg_match('/^"[A-Za-z0-9._~-]{1,128}"$/', $ifMatch)) {
            return $this->v1Error($request, 'invalid_request', 'The If-Match header must be a quoted strong ETag.', Response::HTTP_BAD_REQUEST);
        }

        if (! hash_equals($this->etag($application), $ifMatch)) {
            return $this->v1Error($request, 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
        }

        return null;
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
