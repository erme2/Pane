<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * A member's web push devices (Pane#174). Every route works only on the
 * signed-in member's own devices; another member's device is a 404.
 */
class PushSubscriptionController extends Controller
{
    private const int MAX_ENDPOINT_LENGTH = 2048;

    private const int MAX_KEY_LENGTH = 255;

    private const int MAX_LABEL_LENGTH = 100;

    /** `GET /api/v1/push`: whether push is on, and the public key the browser subscribes with. */
    public function show(Request $request, PushSender $sender): JsonResponse
    {
        $user = $this->user($request);

        if (! $user instanceof User) {
            return $this->authRequired($request);
        }

        $enabled = $sender->configured();

        return $this->ok($request, [
            'enabled' => $enabled,
            'vapid_public_key' => $enabled ? (string) config('pane.push.vapid_public_key') : null,
        ]);
    }

    /** `GET /api/v1/push/subscriptions`: the member's devices. */
    public function list(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $user instanceof User) {
            return $this->authRequired($request);
        }

        $devices = PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(fn (PushSubscription $subscription): array => $this->present($subscription))
            ->all();

        return $this->ok($request, $devices);
    }

    /**
     * `POST /api/v1/push/subscriptions`: registers or refreshes a device. The
     * browser's own subscription JSON is accepted as is (`endpoint`, `keys`).
     * Registering the same endpoint again refreshes its keys and label, and
     * re-creates it for the signed-in member (a browser that changed account).
     */
    public function store(Request $request, PushSender $sender): JsonResponse
    {
        $user = $this->user($request);

        if (! $user instanceof User) {
            return $this->authRequired($request);
        }

        if (! $sender->configured()) {
            return $this->fail($request, 'push_unavailable', 'Web push is not configured on this install.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $body = $request->json()->all();
        $unsupported = array_diff(array_keys($body), ['endpoint', 'keys', 'device_label', 'expirationTime', 'expiration_time']);

        if ($unsupported !== []) {
            return $this->invalid($request, 'The '.reset($unsupported).' field is not supported.');
        }

        $endpoint = $body['endpoint'] ?? null;
        $keys = $body['keys'] ?? null;
        $label = $body['device_label'] ?? null;
        $expiresAt = $body['expiration_time'] ?? $body['expirationTime'] ?? null;

        if (! is_string($endpoint) || ! $this->safeEndpoint($endpoint)) {
            return $this->invalid($request, 'The endpoint field must be an https address of a push service.');
        }

        if (! is_array($keys) || ! $this->validKey($keys['p256dh'] ?? null) || ! $this->validKey($keys['auth'] ?? null)) {
            return $this->invalid($request, 'The keys field must hold the p256dh and auth keys.');
        }

        if ($label !== null && (! is_string($label) || mb_strlen($label) > self::MAX_LABEL_LENGTH)) {
            return $this->invalid($request, 'The device_label field must be text of at most '.self::MAX_LABEL_LENGTH.' characters.');
        }

        if ($expiresAt !== null && (! is_int($expiresAt) && ! is_float($expiresAt) || $expiresAt < 0)) {
            return $this->invalid($request, 'The expiration_time field must be a time in milliseconds.');
        }

        $hash = PushSubscription::hashEndpoint($endpoint);
        $existing = PushSubscription::query()->where('endpoint_hash', $hash)->first();

        // A device that changes member is a new subscription (new id), so pushes
        // queued for the previous member can never reach the new one.
        $transfer = $existing instanceof PushSubscription && $existing->user_id !== (int) $user->getKey();

        if (! $existing instanceof PushSubscription || $transfer) {
            $count = PushSubscription::query()->where('user_id', $user->getKey())->count();

            if ($count >= max(1, (int) config('pane.push.max_subscriptions_per_user'))) {
                return $this->fail($request, 'too_many_devices', 'Remove a device before adding another.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if ($transfer) {
            $existing->delete();
            $existing = null;
        }

        $subscription = $existing ?? new PushSubscription;
        $subscription->fill([
            'user_id' => $user->getKey(),
            'kind' => PushSubscription::KIND_WEB,
            'endpoint' => $endpoint,
            'endpoint_hash' => $hash,
            'p256dh' => $keys['p256dh'],
            'auth' => $keys['auth'],
            'device_label' => $label === null ? null : trim($label),
            'expires_at' => $expiresAt === null ? null : now()->createFromTimestampMs((int) $expiresAt),
        ])->save();

        return $this->ok($request, $this->present($subscription), $existing instanceof PushSubscription ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /** `DELETE /api/v1/push/subscriptions/{id}`: removes one of the member's devices. */
    public function destroy(Request $request, string $subscriptionId): JsonResponse|Response
    {
        $user = $this->user($request);

        if (! $user instanceof User) {
            return $this->authRequired($request);
        }

        $subscription = Str::isUuid($subscriptionId)
            ? PushSubscription::query()->where('user_id', $user->getKey())->find($subscriptionId)
            : null;

        if (! $subscription instanceof PushSubscription) {
            return $this->fail($request, 'not_found', 'Device not found.', Response::HTTP_NOT_FOUND);
        }

        $subscription->delete();

        return response()->noContent()->header('X-Request-Id', $this->requestId($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PushSubscription $subscription): array
    {
        return [
            'id' => $subscription->subscription_id,
            'kind' => $subscription->kind,
            'device_label' => $subscription->device_label,
            // Only the push service's host: the full endpoint is a secret address.
            'service' => parse_url($subscription->endpoint, PHP_URL_HOST) ?: null,
            'created_at' => $subscription->created_at?->toJSON(),
            'last_sent_at' => $subscription->last_sent_at?->toJSON(),
        ];
    }

    private function safeEndpoint(string $endpoint): bool
    {
        if ($endpoint === '' || strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
            return false;
        }

        $parts = parse_url($endpoint);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        // An address literal must be public: never let Pane be asked to call an internal address.
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.');
    }

    private function validKey(mixed $key): bool
    {
        return is_string($key) && $key !== '' && strlen($key) <= self::MAX_KEY_LENGTH && preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $key) === 1;
    }

    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function authRequired(Request $request): JsonResponse
    {
        return $this->fail($request, 'authentication_required', 'Authentication is required.', Response::HTTP_UNAUTHORIZED);
    }

    private function invalid(Request $request, string $message): JsonResponse
    {
        return $this->fail($request, 'validation_failed', $message, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function ok(Request $request, array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $requestId = $this->requestId($request);

        return response()->json(['data' => $data, 'meta' => ['request_id' => $requestId]], $status)
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
