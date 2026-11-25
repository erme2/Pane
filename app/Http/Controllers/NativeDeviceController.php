<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\WorkOsAuthController;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Token refresh and device management for native clients (Pane#130). A member lists and signs out
 * their own devices only; another member's device is a 404.
 */
class NativeDeviceController extends Controller
{
    public function __construct(private readonly DeviceTokenService $devices) {}

    /** `POST /api/v1/auth/native/refresh`: swaps a refresh token for a new access and refresh token. */
    public function refresh(Request $request): JsonResponse
    {
        $body = $request->json()->all();
        $token = $body['refresh_token'] ?? null;

        if (array_diff(array_keys($body), ['refresh_token']) !== [] || ! is_string($token) || $token === '') {
            return $this->fail($request, 'validation_failed', 'The refresh_token field is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $issued = $this->devices->refresh($token);

        if ($issued === null) {
            return $this->fail($request, 'invalid_refresh_token', 'The refresh token is not valid. Sign in again.', Response::HTTP_UNAUTHORIZED);
        }

        return $this->ok($request, WorkOsAuthController::nativeTokenResource($issued))
            ->header('Cache-Control', 'no-store');
    }

    /** `GET /api/v1/session/devices`: the member's signed-in native devices. */
    public function list(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->fail($request, 'authentication_required', 'Authentication is required.', Response::HTTP_UNAUTHORIZED);
        }

        $current = $this->devices->deviceForRequest($request)?->device_id;
        $data = DeviceToken::query()
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(fn (DeviceToken $device): array => [
                'id' => $device->device_id,
                'device_label' => $device->device_label,
                'created_at' => $device->created_at?->toJSON(),
                'last_used_at' => $device->last_used_at?->toJSON(),
                'current' => $device->device_id === $current,
            ])
            ->all();

        return $this->ok($request, $data);
    }

    /** `DELETE /api/v1/session/devices/{id}`: signs one of the member's devices out. */
    public function destroy(Request $request, string $deviceId): JsonResponse|Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->fail($request, 'authentication_required', 'Authentication is required.', Response::HTTP_UNAUTHORIZED);
        }

        $device = Str::isUuid($deviceId)
            ? DeviceToken::query()->where('user_id', $user->getKey())->find($deviceId)
            : null;

        if (! $device instanceof DeviceToken) {
            return $this->fail($request, 'not_found', 'Device not found.', Response::HTTP_NOT_FOUND);
        }

        $this->devices->revoke($device);

        return response()->noContent()->header('X-Request-Id', $this->requestId($request));
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function ok(Request $request, array $data): JsonResponse
    {
        $requestId = $this->requestId($request);

        return response()->json(['data' => $data, 'meta' => ['request_id' => $requestId]])
            ->header('X-Request-Id', $requestId);
    }

    private function fail(Request $request, string $code, string $message, int $status): JsonResponse
    {
        $requestId = $this->requestId($request);

        return response()->json([
            'error' => ['code' => $code, 'message' => $message, 'request_id' => $requestId],
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
