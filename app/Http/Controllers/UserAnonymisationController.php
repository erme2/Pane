<?php

namespace App\Http\Controllers;

use App\Exceptions\UserAnonymisationException;
use App\Models\User;
use App\Services\UserAnonymisationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class UserAnonymisationController extends Controller
{
    public function __construct(private readonly UserAnonymisationService $anonymisation) {}

    /**
     * `DELETE /api/v1/users/{userId}`: anonymise a user by UUID. A user may do it to themselves;
     * an active Pane administrator may do it for anyone (a request received by email).
     */
    public function destroy(Request $request, string $userId): Response|JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return $this->v1Error($request, 'permission_denied', 'Authentication is required.', Response::HTTP_FORBIDDEN);
        }

        if (! Str::isUuid($userId)) {
            return $this->v1Error($request, 'invalid_identifier', 'The user_id path parameter must be a valid UUID.', Response::HTTP_BAD_REQUEST);
        }

        $target = User::query()->where('uuid', strtolower($userId))->first();

        // A non-admin gets the same answer for a missing user as for someone else's.
        if (! $target instanceof User) {
            return $actor->isPaneAdministrator()
                ? $this->v1Error($request, 'resource_not_found', 'The requested resource was not found.', Response::HTTP_NOT_FOUND)
                : $this->v1Error($request, 'permission_denied', 'Only the user or an active Pane administrator can anonymise a user.', Response::HTTP_FORBIDDEN);
        }

        $selfDeletion = $actor->is($target);

        try {
            $this->anonymisation->anonymise($actor, $target);
        } catch (UserAnonymisationException $exception) {
            return $this->v1Error($request, 'dependency_unavailable', $exception->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (DomainException $exception) {
            return str_contains($exception->getMessage(), 'final active')
                ? $this->v1Error($request, 'operation_conflict', $exception->getMessage(), Response::HTTP_CONFLICT)
                : $this->v1Error($request, 'permission_denied', $exception->getMessage(), Response::HTTP_FORBIDDEN);
        }

        if ($selfDeletion) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent()->header('X-Request-Id', $this->requestId($request));
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
}
