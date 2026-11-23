<?php

namespace App\Http\Controllers;

use App\Exceptions\InvitationVersionConflict;
use App\Mail\InvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use App\Support\LatteApplicationConfig;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * List, create and revoke user invitations. Pane administrators only; the response shapes mirror
 * PaneAdminInvitationController.
 */
class UserInvitationController extends Controller
{
    private const string DENIED = 'Only active Pane administrators can manage user invitations.';

    public function __construct(private readonly UserInvitationService $invitations) {}

    public function list(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isPaneAdministrator()) {
            return $this->v1Error($request, 'permission_denied', self::DENIED, Response::HTTP_FORBIDDEN);
        }

        $page = $this->pageParameters($request);

        if ($page instanceof JsonResponse) {
            return $page;
        }

        $query = UserInvitation::query()
            ->orderByDesc('created_at')
            ->orderByDesc('user_invitation_id');

        if ($page['cursor'] !== null) {
            $cursorInvitation = $this->invitationFromCursor($page['cursor']);

            if (! $cursorInvitation instanceof UserInvitation) {
                return $this->v1Error($request, 'invalid_cursor', 'The page cursor is invalid.', Response::HTTP_BAD_REQUEST);
            }

            $query->where(function ($query) use ($cursorInvitation): void {
                $query
                    ->where('created_at', '<', $cursorInvitation->created_at)
                    ->orWhere(function ($query) use ($cursorInvitation): void {
                        $query
                            ->where('created_at', $cursorInvitation->created_at)
                            ->where('user_invitation_id', '<', $cursorInvitation->getKey());
                    });
            });
        }

        $invitations = $query->limit($page['limit'] + 1)->get();
        $hasMore = $invitations->count() > $page['limit'];
        $visible = $invitations->take($page['limit']);
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $visible->map(fn (UserInvitation $invitation): array => $this->resource($invitation))->values(),
            'meta' => [
                'request_id' => $requestId,
                'page' => [
                    'next_cursor' => $hasMore ? $this->cursorForInvitation($visible->last()) : null,
                    'has_more' => $hasMore,
                ],
            ],
        ])->header('X-Request-Id', $requestId);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->v1Error($request, 'permission_denied', self::DENIED, Response::HTTP_FORBIDDEN);
        }

        $unsupportedFields = array_values(array_diff(array_keys($request->all()), ['email']));

        if ($unsupportedFields !== []) {
            sort($unsupportedFields);

            return $this->v1Error($request, 'validation_failed', 'The '.$unsupportedFields[0].' field is not supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:320'],
        ]);

        if ($validator->fails()) {
            return $this->v1Error($request, 'validation_failed', 'The email field must be a valid email address.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->invitations->invite($actor, (string) $request->input('email'));
        } catch (DomainException $exception) {
            return str_contains($exception->getMessage(), 'already belongs')
                ? $this->v1Error($request, 'operation_conflict', $exception->getMessage(), Response::HTTP_CONFLICT)
                : $this->v1Error($request, 'permission_denied', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        $invitation = $result['invitation'];
        $requestId = $this->requestId($request);
        $invitationUrl = $this->invitationUrl($result['token']);

        return response()->json([
            'data' => $this->resource($invitation),
            'meta' => [
                'request_id' => $requestId,
                'invitation_url' => $invitationUrl,
                'email_sent' => $this->sendInvitationEmail($actor, $invitation, $invitationUrl),
            ],
        ], Response::HTTP_CREATED)
            ->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($invitation));
    }

    /**
     * Emails a fresh link for a pending invitation. No new invitation row is created.
     */
    public function resend(Request $request, string $invitationId): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->v1Error($request, 'permission_denied', self::DENIED, Response::HTTP_FORBIDDEN);
        }

        if (! Str::isUuid($invitationId)) {
            return $this->v1Error($request, 'invalid_identifier', 'The invitation_id path parameter must be a valid UUID.', Response::HTTP_BAD_REQUEST);
        }

        if (! $actor->isPaneAdministrator()) {
            return $this->v1Error($request, 'permission_denied', self::DENIED, Response::HTTP_FORBIDDEN);
        }

        $invitation = UserInvitation::query()->find($invitationId);

        if (! $invitation instanceof UserInvitation) {
            return $this->v1Error($request, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
        }

        try {
            $result = $this->invitations->resend($actor, $invitation);
        } catch (DomainException $exception) {
            return str_contains($exception->getMessage(), 'pending')
                ? $this->v1Error($request, 'operation_conflict', $exception->getMessage(), Response::HTTP_CONFLICT)
                : $this->v1Error($request, 'permission_denied', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        $invitation = $result['invitation'];
        $requestId = $this->requestId($request);
        $invitationUrl = $this->invitationUrl($result['token']);

        return response()->json([
            'data' => $this->resource($invitation),
            'meta' => [
                'request_id' => $requestId,
                'invitation_url' => $invitationUrl,
                'email_sent' => $this->sendInvitationEmail($actor, $invitation, $invitationUrl),
            ],
        ])
            ->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($invitation));
    }

    /**
     * The invitation already exists when this runs, so a mail failure is logged and reported in the
     * response (email_sent); the link can still be shared by hand or resent.
     */
    private function sendInvitationEmail(User $actor, UserInvitation $invitation, string $invitationUrl): bool
    {
        try {
            Mail::to($invitation->email)->send(new InvitationMail(
                invitationUrl: $invitationUrl,
                role: 'user',
                inviterName: (string) ($actor->name ?: $actor->email),
                expiresAt: $invitation->expires_at->toDateTimeString(),
            ));
        } catch (Throwable $exception) {
            Log::warning('User invitation email failed', [
                'invitation_id' => $invitation->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    public function destroy(Request $request, string $invitationId): Response|JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->v1Error($request, 'permission_denied', self::DENIED, Response::HTTP_FORBIDDEN);
        }

        if (! Str::isUuid($invitationId)) {
            return $this->v1Error($request, 'invalid_identifier', 'The invitation_id path parameter must be a valid UUID.', Response::HTTP_BAD_REQUEST);
        }

        if (! $actor->isPaneAdministrator()) {
            return $this->v1Error($request, 'permission_denied', self::DENIED, Response::HTTP_FORBIDDEN);
        }

        $invitation = UserInvitation::query()->find($invitationId);

        if (! $invitation instanceof UserInvitation) {
            return $this->v1Error($request, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
        }

        $precondition = $this->ifMatchPrecondition($request, $invitation);

        if ($precondition instanceof JsonResponse) {
            return $precondition;
        }

        $ifMatch = (string) $request->header('If-Match');

        try {
            // The ETag is checked again against the locked row, so an acceptance that commits
            // after the read above cannot be revoked with the old ETag.
            $this->invitations->revoke(
                $actor,
                $invitation,
                fn (UserInvitation $locked): bool => hash_equals($this->etag($locked), $ifMatch),
            );
        } catch (DomainException $exception) {
            if ($exception instanceof InvitationVersionConflict) {
                return $this->v1Error($request, 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
            }

            return $this->v1Error($request, 'permission_denied', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return response()->noContent()->header('X-Request-Id', $this->requestId($request));
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function resource(UserInvitation $invitation): array
    {
        return [
            'id' => (string) $invitation->getKey(),
            'type' => 'invitation',
            'attributes' => [
                'scope' => 'installation',
                'email' => $invitation->email,
                'role' => 'user',
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

    private function etag(UserInvitation $invitation): string
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

        return ['limit' => $limit, 'cursor' => $cursor];
    }

    private function cursorForInvitation(?UserInvitation $invitation): ?string
    {
        if (! $invitation instanceof UserInvitation) {
            return null;
        }

        return rtrim(strtr(base64_encode(json_encode([
            'v' => 1,
            'id' => (string) $invitation->getKey(),
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function invitationFromCursor(string $cursor): ?UserInvitation
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

        return UserInvitation::query()->find($payload['id']);
    }

    private function ifMatchPrecondition(Request $request, UserInvitation $invitation): ?JsonResponse
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
        $normalized = LatteApplicationConfig::normalizeRedirectUri((string) config('services.latte.frontend_url'));
        $parts = $normalized === null ? false : parse_url($normalized);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            $base = LatteApplicationConfig::trustedOrigin();
        } else {
            $port = isset($parts['port']) ? ':'.$parts['port'] : '';
            $path = isset($parts['path']) && $parts['path'] !== '/' ? rtrim($parts['path'], '/') : '';
            $base = $parts['scheme'].'://'.$parts['host'].$port.$path;
        }

        return $base.'/auth/login?'.http_build_query([
            'invitation_token' => $token,
            'redirect_to' => LatteApplicationConfig::redirectUris()[0],
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
