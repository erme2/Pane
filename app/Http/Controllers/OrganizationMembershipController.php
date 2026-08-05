<?php

namespace App\Http\Controllers;

use App\Models\ApplicationRegistration;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OrganizationTenancyService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

class OrganizationMembershipController extends Controller
{
    public function __construct(private readonly OrganizationTenancyService $tenancy) {}

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
        $query = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('membership_id');

        if ($page['cursor'] !== null) {
            $cursorMembership = $this->membershipFromCursor($page['cursor'], $organization);

            if (! $cursorMembership instanceof OrganizationMembership) {
                return $this->v1Error($request, 'invalid_cursor', 'The page cursor is invalid.', Response::HTTP_BAD_REQUEST);
            }

            $query->where(function ($query) use ($cursorMembership): void {
                $query
                    ->where('created_at', '<', $cursorMembership->created_at)
                    ->orWhere(function ($query) use ($cursorMembership): void {
                        $query
                            ->where('created_at', $cursorMembership->created_at)
                            ->where('membership_id', '<', $cursorMembership->getKey());
                    });
            });
        }

        $memberships = $query
            ->with('user')
            ->limit($page['limit'] + 1)
            ->get();
        $hasMore = $memberships->count() > $page['limit'];
        $visibleMemberships = $memberships->take($page['limit']);
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $visibleMemberships
                ->map(fn (OrganizationMembership $membership): array => $this->resource($membership))
                ->values(),
            'meta' => [
                'request_id' => $requestId,
                'page' => [
                    'next_cursor' => $hasMore ? $this->cursorForMembership($visibleMemberships->last()) : null,
                    'has_more' => $hasMore,
                ],
            ],
        ])->header('X-Request-Id', $requestId);
    }

    public function show(Request $request, string $organizationId, string $membershipId): JsonResponse
    {
        $context = $this->organizationAdministrationContext($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $membership = $this->scopedMembershipOrError($request, $context['organization'], $membershipId);

        if ($membership instanceof JsonResponse) {
            return $membership;
        }

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($membership),
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($membership));
    }

    public function update(Request $request, string $organizationId, string $membershipId): JsonResponse
    {
        $context = $this->organizationAdministrationContext($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        $membership = $this->scopedMembershipOrError($request, $context['organization'], $membershipId);

        if ($membership instanceof JsonResponse) {
            return $membership;
        }

        $precondition = $this->ifMatchPrecondition($request, $membership);

        if ($precondition instanceof JsonResponse) {
            return $precondition;
        }

        $unsupportedFields = array_values(array_diff(array_keys($request->all()), ['role', 'status']));

        if ($unsupportedFields !== []) {
            sort($unsupportedFields);

            return $this->v1Error($request, 'validation_failed', 'The '.$unsupportedFields[0].' field is not supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validator = Validator::make($request->all(), [
            'role' => ['sometimes', 'required', 'in:'.implode(',', OrganizationMembership::ROLES)],
            'status' => ['sometimes', 'required', 'in:'.implode(',', OrganizationMembership::STATUSES)],
        ]);

        if ($validator->fails() || $request->all() === []) {
            return $this->v1Error($request, 'validation_failed', 'At least one supported role or status field is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $updated = $this->applyMembershipUpdate(
                $context['organization'],
                $membership,
                $request->input('role'),
                $request->input('status')
            );
        } catch (InvalidArgumentException $exception) {
            return $this->v1Error($request, 'validation_failed', $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DomainException $exception) {
            return $this->v1Error($request, 'operation_conflict', $exception->getMessage(), Response::HTTP_CONFLICT);
        }

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $this->resource($updated),
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId)
            ->header('ETag', $this->etag($updated));
    }

    private function applyMembershipUpdate(
        Organization $organization,
        OrganizationMembership $membership,
        mixed $role,
        mixed $status
    ): OrganizationMembership {
        $targetRole = is_string($role) ? $role : $membership->role;
        $targetStatus = is_string($status) ? $status : $membership->status;

        if ($targetStatus === OrganizationMembership::STATUS_ACTIVE) {
            $user = $membership->user()->firstOrFail();

            return $this->tenancy->addOrReactivateMembership($organization, $user, $targetRole);
        }

        if ($targetRole !== $membership->role) {
            $user = $membership->user()->firstOrFail();
            $membership = $this->tenancy->addOrReactivateMembership($organization, $user, $targetRole);
        }

        if ($targetStatus === OrganizationMembership::STATUS_SUSPENDED) {
            return $this->tenancy->suspendMembership($membership);
        }

        return $membership->refresh();
    }

    /**
     * @return array{actor: User, application: ApplicationRegistration, organization: Organization, membership: OrganizationMembership}|JsonResponse
     */
    private function organizationAdministrationContext(Request $request): array|JsonResponse
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
            return $this->v1Error($request, 'permission_denied', 'Only active organization administrators can manage organization memberships.', Response::HTTP_FORBIDDEN);
        }

        return [
            'actor' => $actor,
            'application' => $application,
            'organization' => $organization,
            'membership' => $membership,
        ];
    }

    private function scopedMembershipOrError(
        Request $request,
        Organization $organization,
        string $membershipId
    ): OrganizationMembership|JsonResponse {
        if (! Str::isUuid($membershipId)) {
            return $this->v1Error($request, 'invalid_identifier', 'The membership_id path parameter must be a valid UUID.', Response::HTTP_BAD_REQUEST);
        }

        $membership = OrganizationMembership::query()
            ->with('user')
            ->where('organization_id', $organization->getKey())
            ->whereKey($membershipId)
            ->first();

        return $membership instanceof OrganizationMembership
            ? $membership
            : $this->v1Error($request, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND);
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function resource(OrganizationMembership $membership): array
    {
        $user = $membership->user;

        return [
            'id' => (string) $membership->getKey(),
            'type' => 'membership',
            'attributes' => [
                'organization_id' => $membership->organization_id,
                'user_id' => (string) $membership->user_id,
                'user_email' => $user instanceof User ? $user->email : null,
                'user_name' => $user instanceof User ? $user->name : null,
                'role' => $membership->role,
                'status' => $membership->status,
                'created_at' => $membership->created_at?->toJSON(),
                'updated_at' => $membership->updated_at?->toJSON(),
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

    private function etag(OrganizationMembership $membership): string
    {
        return '"'.$membership->versionTag().'"';
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

    private function cursorForMembership(?OrganizationMembership $membership): ?string
    {
        if (! $membership instanceof OrganizationMembership) {
            return null;
        }

        return rtrim(strtr(base64_encode(json_encode([
            'v' => 1,
            'id' => (string) $membership->getKey(),
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    private function membershipFromCursor(string $cursor, Organization $organization): ?OrganizationMembership
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

        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->find($payload['id']);

        return $membership instanceof OrganizationMembership ? $membership : null;
    }

    private function ifMatchPrecondition(Request $request, OrganizationMembership $membership): ?JsonResponse
    {
        $ifMatch = $request->header('If-Match');

        if ($ifMatch === null || $ifMatch === '') {
            return $this->v1Error($request, 'precondition_required', 'The If-Match header is required for this operation.', Response::HTTP_PRECONDITION_REQUIRED);
        }

        if (! preg_match('/\A"[A-Za-z0-9._~-]{1,128}"\z/', $ifMatch)) {
            return $this->v1Error($request, 'invalid_request', 'The If-Match header must be a quoted strong ETag.', Response::HTTP_BAD_REQUEST);
        }

        if (! hash_equals($this->etag($membership), $ifMatch)) {
            return $this->v1Error($request, 'version_conflict', 'The resource version does not match the If-Match header.', Response::HTTP_PRECONDITION_FAILED);
        }

        return null;
    }
}
