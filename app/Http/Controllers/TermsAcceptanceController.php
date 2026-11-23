<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class TermsAcceptanceController extends Controller
{
    private const int MAX_VERSION_LENGTH = 100;

    /**
     * `PUT /api/v1/session/terms`: the signed-in user accepts a Terms and Privacy version.
     *
     * The version ID is an opaque string from the Sweegly website; Pane does not check its
     * content or compare it with the stored one, so the latest acceptance always wins.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->v1Error($request, 'authentication_required', 'Authentication is required.', Response::HTTP_UNAUTHORIZED);
        }

        $unsupported = array_diff(array_keys($request->json()->all()), ['terms_version']);

        if ($unsupported !== []) {
            return $this->v1Error($request, 'validation_failed', 'The '.reset($unsupported).' field is not supported.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $version = $request->json('terms_version');

        if (! is_string($version) || trim($version) === '' || mb_strlen($version) > self::MAX_VERSION_LENGTH) {
            return $this->v1Error($request, 'validation_failed', 'The terms_version field must be a non-empty string of at most '.self::MAX_VERSION_LENGTH.' characters.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->forceFill([
            'terms_version' => $version,
            'terms_accepted_at' => now(),
        ])->save();

        $requestId = $this->requestId($request);

        return response()->json([
            'data' => [
                'terms_version' => $user->terms_version,
                'terms_accepted_at' => $user->terms_accepted_at?->toJSON(),
            ],
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId);
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
