<?php

namespace App\Http\Controllers;

use App\Models\PaneAdminInvitation;
use App\Models\User;
use App\Services\PaneAdminLifecycleService;
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

        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $invitations = PaneAdminInvitation::query()
            ->orderByDesc('created_at')
            ->orderByDesc('pane_admin_invitation_id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $invitations->count() > $limit;

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => $invitations->take($limit)->map(fn (PaneAdminInvitation $invitation): array => $this->resource($invitation))->values(),
            'meta' => [
                'request_id' => $requestId,
                'page' => [
                    'next_cursor' => $hasMore ? (string) $invitations->last()?->getKey() : null,
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
            'meta' => ['request_id' => $requestId],
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
}
