<?php

namespace App\Http\Controllers;

use App\Models\PaneAdminInvitation;
use App\Models\User;
use App\Services\PaneAdminLifecycleService;
use App\Support\LatteApplicationConfig;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PaneAdminInvitationController extends Controller
{
    public function __construct(private readonly PaneAdminLifecycleService $administrators) {}

    public function list(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isPaneAdministrator()) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage Pane administrator invitations.', Response::HTTP_FORBIDDEN);
        }

        $page = $this->pageParameters($request);

        if ($page instanceof JsonResponse) {
            return $page;
        }

        $query = PaneAdminInvitation::query()
            ->orderByDesc('created_at')
            ->orderByDesc('pane_admin_invitation_id');

        if ($page['cursor'] !== null) {
            $cursorInvitation = $this->invitationFromCursor($page['cursor']);

            if (! $cursorInvitation instanceof PaneAdminInvitation) {
                return $this->v1Error($request, 'invalid_cursor', 'The page cursor is invalid.', Response::HTTP_BAD_REQUEST);
            }

            $query->where(function ($query) use ($cursorInvitation): void {
                $query
                    ->where('created_at', '<', $cursorInvitation->created_at)
                    ->orWhere(function ($query) use ($cursorInvitation): void {
                        $query
                            ->where('created_at', $cursorInvitation->created_at)
                            ->where('pane_admin_invitation_id', '<', $cursorInvitation->getKey());
                    });
            });
        }

        $invitations = $query
            ->limit($page['limit'] + 1)
            ->get();
        $hasMore = $invitations->count() > $page['limit'];
        $visibleInvitations = $invitations->take($page['limit']);

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $visibleInvitations->map(fn (PaneAdminInvitation $invitation): array => $this->resource($invitation))->values(),
            'meta' => [
                'request_id' => $requestId,
                'page' => [
                    'next_cursor' => $hasMore ? $this->cursorForInvitation($visibleInvitations->last()) : null,
                    'has_more' => $hasMore,
                ],
            ],
        ])->header('X-Request-Id', $requestId);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage Pane administrator invitations.', Response::HTTP_FORBIDDEN);
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:320'],
        ]);

        if ($validator->fails()) {
            return $this->v1Error($request, 'validation_failed', 'The email field must be a valid email address.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->administrators->invitePaneAdministrator($actor, (string) $request->input('email'));
        } catch (DomainException $exception) {
            return str_contains($exception->getMessage(), 'already belongs')
                ? $this->v1Error($request, 'operation_conflict', $exception->getMessage(), Response::HTTP_CONFLICT)
                : $this->v1Error($request, 'permission_denied', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        /** @var PaneAdminInvitation $invitation */
        $invitation = $result['invitation'];
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($invitation),
            'meta' => [
                'request_id' => $requestId,
                'invitation_url' => $this->invitationUrl((string) $result['token']),
            ],
        ], Response::HTTP_CREATED)
            ->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($invitation));
    }

    public function destroy(Request $request, string $invitationId): Response|JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->v1Error($request, 'permission_denied', 'Only active Pane administrators can manage Pane administrator invitations.', Response::HTTP_FORBIDDEN);
        }

        $invitation = PaneAdminInvitation::query()->find($invitationId);

        if (! $invitation instanceof PaneAdminInvitation) {
            return $this->v1Error($request, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
        }

        $precondition = $this->ifMatchPrecondition($request, $invitation);

        if ($precondition instanceof JsonResponse) {
            return $precondition;
        }

        try {
            $this->administrators->revokePaneAdministratorInvitation($actor, $invitation);
        } catch (DomainException $exception) {
            return $this->v1Error($request, 'permission_denied', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return response()->noContent()
            ->header('X-Request-Id', $this->requestId($request));
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function resource(PaneAdminInvitation $invitation): array
    {
        return [
            'id' => (string) $invitation->getKey(),
            'type' => 'invitation',
            'attributes' => [
                'scope' => 'installation',
                'organization_id' => null,
                'email' => $invitation->email,
                'role' => 'pane_administrator',
                'status' => $invitation->status,
                'expires_at' => $invitation->expires_at->toJSON(),
                'created_at' => $invitation->created_at?->toJSON(),
                'updated_at' => $invitation->updated_at?->toJSON(),
            ],
        ];
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

    private function etag(PaneAdminInvitation $invitation): string
    {
        return '"'.hash('sha256', implode('|', [
            $invitation->getKey(),
            $invitation->status,
            $invitation->updated_at?->toJSON(),
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

    private function cursorForInvitation(?PaneAdminInvitation $invitation): ?string
    {
        if (! $invitation instanceof PaneAdminInvitation) {
            return null;
        }

        return rtrim(strtr(base64_encode(json_encode([
            'v' => 1,
            'id' => (string) $invitation->getKey(),
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function invitationFromCursor(string $cursor): ?PaneAdminInvitation
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

        return PaneAdminInvitation::query()->find($payload['id']);
    }

    private function ifMatchPrecondition(Request $request, PaneAdminInvitation $invitation): ?JsonResponse
    {
        $ifMatch = $request->header('If-Match');

        if ($ifMatch === null || $ifMatch === '') {
            return $this->v1Error($request, 'precondition_required', 'The If-Match header is required for this operation.', Response::HTTP_PRECONDITION_REQUIRED);
        }

        if (! preg_match('/\A"[A-Za-z0-9._~-]{1,128}"\z/', $ifMatch)) {
            return $this->v1Error($request, 'invalid_request', 'The If-Match header must be a quoted strong ETag.', Response::HTTP_BAD_REQUEST);
        }

        if (! hash_equals($this->etag($invitation), $ifMatch)) {
            return $this->v1Error($request, 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
        }

        return null;
    }

    private function invitationUrl(string $token): string
    {
        return $this->latteFrontendBaseUrl().'/auth/login?'.http_build_query([
            'invitation_token' => $token,
            'redirect_to' => LatteApplicationConfig::redirectUris()[0],
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function latteFrontendBaseUrl(): string
    {
        $normalized = LatteApplicationConfig::normalizeRedirectUri((string) config('services.latte.frontend_url'));

        if ($normalized === null) {
            return LatteApplicationConfig::trustedOrigin();
        }

        $parts = parse_url($normalized);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return LatteApplicationConfig::trustedOrigin();
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = isset($parts['path']) && $parts['path'] !== '/' ? rtrim($parts['path'], '/') : '';

        return $parts['scheme'].'://'.$parts['host'].$port.$path;
    }
}
