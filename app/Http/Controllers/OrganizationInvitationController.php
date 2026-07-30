<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OrganizationInvitationService;
use App\Support\LatteApplicationConfig;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OrganizationInvitationController extends Controller
{
    public function __construct(private readonly OrganizationInvitationService $invitations) {}

    public function list(Request $request, string $organizationId): JsonResponse
    {
        $context = $this->organizationAdministrationContext($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $page = $this->pageParameters($request);

        if ($page instanceof JsonResponse) {
            return $page;
        }

        $organization = $context['organization'];
        $query = OrganizationInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('organization_invitation_id');

        if ($page['cursor'] !== null) {
            $cursorInvitation = $this->invitationFromCursor($page['cursor'], $organization);

            if (! $cursorInvitation instanceof OrganizationInvitation) {
                return $this->v1Error($request, 'invalid_cursor', 'The page cursor is invalid.', Response::HTTP_BAD_REQUEST);
            }

            $query->where(function ($query) use ($cursorInvitation): void {
                $query
                    ->where('created_at', '<', $cursorInvitation->created_at)
                    ->orWhere(function ($query) use ($cursorInvitation): void {
                        $query
                            ->where('created_at', $cursorInvitation->created_at)
                            ->where('organization_invitation_id', '<', $cursorInvitation->getKey());
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
            'data' => $visibleInvitations
                ->map(fn (OrganizationInvitation $invitation): array => $this->resource($invitation))
                ->values(),
            'meta' => [
                'request_id' => $requestId,
                'page' => [
                    'next_cursor' => $hasMore ? $this->cursorForInvitation($visibleInvitations->last()) : null,
                    'has_more' => $hasMore,
                ],
            ],
        ])->header('X-Request-Id', $requestId);
    }

    public function store(Request $request, string $organizationId): JsonResponse
    {
        $context = $this->organizationAdministrationContext($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $unsupportedFields = array_values(array_diff(array_keys($request->all()), ['email', 'role']));

        if ($unsupportedFields !== []) {
            sort($unsupportedFields);

            return $this->v1Error($request, 'validation_failed', 'The '.$unsupportedFields[0].' field is not supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:320'],
            'role' => ['required', 'in:'.implode(',', OrganizationMembership::ROLES)],
        ]);

        if ($validator->fails()) {
            return $this->v1Error($request, 'validation_failed', 'The email field must be valid and role must be supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->invitations->inviteOrganizationMember(
                $context['actor'],
                $context['organization'],
                (string) $request->input('email'),
                (string) $request->input('role')
            );
        } catch (InvalidArgumentException $exception) {
            return $this->v1Error($request, 'validation_failed', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DomainException $exception) {
            return str_contains($exception->getMessage(), 'already belongs')
                ? $this->v1Error($request, 'operation_conflict', $exception->getMessage(), Response::HTTP_CONFLICT)
                : $this->v1Error($request, 'permission_denied', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        /** @var OrganizationInvitation $invitation */
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

    public function resend(Request $request, string $organizationId, string $invitationId): JsonResponse
    {
        $context = $this->organizationAdministrationContext($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $invitation = $this->scopedInvitationOrError($request, $context['organization'], $invitationId);

        if ($invitation instanceof JsonResponse) {
            return $invitation;
        }

        $precondition = $this->ifMatchPrecondition($request, $invitation);

        if ($precondition instanceof JsonResponse) {
            return $precondition;
        }

        try {
            $result = $this->invitations->resendOrganizationInvitation(
                $context['actor'],
                $context['organization'],
                $invitation
            );
        } catch (DomainException $exception) {
            return str_contains($exception->getMessage(), 'already belongs')
                ? $this->v1Error($request, 'operation_conflict', $exception->getMessage(), Response::HTTP_CONFLICT)
                : $this->v1Error($request, 'operation_conflict', $exception->getMessage(), Response::HTTP_CONFLICT);
        }

        /** @var OrganizationInvitation $replacement */
        $replacement = $result['invitation'];
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($replacement),
            'meta' => [
                'request_id' => $requestId,
                'invitation_url' => $this->invitationUrl((string) $result['token']),
            ],
        ])->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($replacement));
    }

    public function destroy(Request $request, string $organizationId, string $invitationId): Response|JsonResponse
    {
        $context = $this->organizationAdministrationContext($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $invitation = $this->scopedInvitationOrError($request, $context['organization'], $invitationId);

        if ($invitation instanceof JsonResponse) {
            return $invitation;
        }

        $precondition = $this->ifMatchPrecondition($request, $invitation);

        if ($precondition instanceof JsonResponse) {
            return $precondition;
        }

        try {
            $this->invitations->revokeOrganizationInvitation(
                $context['actor'],
                $context['organization'],
                $invitation
            );
        } catch (DomainException $exception) {
            return $this->v1Error($request, 'permission_denied', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        return response()->noContent()
            ->header('X-Request-Id', $this->requestId($request));
    }

    /**
     * @return array{actor: User, organization: Organization, membership: OrganizationMembership}|JsonResponse
     */
    private function organizationAdministrationContext(Request $request): array|JsonResponse
    {
        $actor = $request->user();
        $organization = $request->attributes->get('pane_v1_organization');
        $membership = $request->attributes->get('pane_v1_membership');

        if (
            ! $actor instanceof User
            || ! $organization instanceof Organization
            || ! $membership instanceof OrganizationMembership
            || ! $membership->isAdministrator()
        ) {
            return $this->v1Error($request, 'permission_denied', 'Only active organization administrators can manage organization invitations.', Response::HTTP_FORBIDDEN);
        }

        return [
            'actor' => $actor,
            'organization' => $organization,
            'membership' => $membership,
        ];
    }

    private function scopedInvitationOrError(
        Request $request,
        Organization $organization,
        string $invitationId
    ): OrganizationInvitation|JsonResponse {
        if (! Str::isUuid($invitationId)) {
            return $this->v1Error($request, 'invalid_identifier', 'The invitation_id path parameter must be a valid UUID.', Response::HTTP_BAD_REQUEST);
        }

        $invitation = OrganizationInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($invitationId)
            ->first();

        return $invitation instanceof OrganizationInvitation
            ? $invitation
            : $this->v1Error($request, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function resource(OrganizationInvitation $invitation): array
    {
        return [
            'id' => (string) $invitation->getKey(),
            'type' => 'invitation',
            'attributes' => [
                'scope' => 'organization',
                'organization_id' => $invitation->organization_id,
                'email' => $invitation->email,
                'role' => $invitation->role,
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

    private function etag(OrganizationInvitation $invitation): string
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

    private function cursorForInvitation(?OrganizationInvitation $invitation): ?string
    {
        if (! $invitation instanceof OrganizationInvitation) {
            return null;
        }

        return rtrim(strtr(base64_encode(json_encode([
            'v' => 1,
            'id' => (string) $invitation->getKey(),
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function invitationFromCursor(string $cursor, Organization $organization): ?OrganizationInvitation
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

        $invitation = OrganizationInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->find($payload['id']);

        return $invitation instanceof OrganizationInvitation ? $invitation : null;
    }

    private function ifMatchPrecondition(Request $request, OrganizationInvitation $invitation): ?JsonResponse
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
