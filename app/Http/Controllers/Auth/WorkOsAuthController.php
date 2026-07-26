<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WorkOsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class WorkOsAuthController extends Controller
{
    private const STATE_COOKIE = 'pane_workos_state';

    public function __construct(private readonly WorkOsService $workOs) {}

    public function loginUrl(Request $request): JsonResponse
    {
        return $this->loginIntentResponse($request, false);
    }

    public function csrfCookie(Request $request): Response
    {
        $request->session()->regenerateToken();

        return $this->versionedNoContentResponse($request);
    }

    public function loginIntent(Request $request): JsonResponse
    {
        return $this->loginIntentResponse($request, true);
    }

    public function session(Request $request): JsonResponse
    {
        return $this->versionedAuthenticatedResponse($request);
    }

    public function destroySession(Request $request): Response
    {
        Auth::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->versionedNoContentResponse($request);
    }

    private function loginIntentResponse(Request $request, bool $versioned): JsonResponse
    {
        $intendedRedirectUrl = $versioned
            ? $this->versionedIntendedRedirectUrl($request)
            : $this->intendedRedirectUrl($request);

        if ($intendedRedirectUrl instanceof JsonResponse) {
            return $intendedRedirectUrl;
        }

        $this->workOs->ensureConfigured();

        $state = $this->workOs->makeState();

        $request->session()->put('workos_state', $state);
        $request->session()->put('workos_intended_url', $intendedRedirectUrl);

        $intent = [
            'authorization_url' => $this->workOs->authorizationUrl($state),
            'state' => $state,
        ];
        $requestId = $this->requestId($request);
        $payload = $versioned
            ? ['data' => $intent, 'meta' => ['request_id' => $requestId]]
            : $intent;

        $response = response()->json($payload)
            ->cookie(self::STATE_COOKIE, $state, 10, '/', null, false, true, false, 'lax');

        return $versioned
            ? $response->header('X-Request-Id', $requestId)
            : $response;
    }

    public function login(Request $request): RedirectResponse
    {
        $response = $this->loginUrl($request)->getData(true);

        return redirect()->away($response['authorization_url']);
    }

    public function callback(Request $request): RedirectResponse|Response
    {
        if ($request->filled('error')) {
            return redirect('/')
                ->withErrors(['workos' => $request->query('error_description', $request->query('error'))]);
        }

        $response = $this->completeCallback($request);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            return response($response->getData(true)['message'], $response->getStatusCode());
        }

        return redirect()->to($request->session()->pull('workos_intended_url', url('/')));
    }

    public function completeCallback(Request $request): JsonResponse
    {
        return $this->completeCallbackResponse($request, false);
    }

    public function completeV1Callback(Request $request): JsonResponse
    {
        return $this->completeCallbackResponse($request, true);
    }

    private function completeCallbackResponse(Request $request, bool $versioned): JsonResponse
    {
        if ($request->filled('error')) {
            return $this->callbackErrorResponse(
                $request,
                $versioned,
                (string) $request->input('error_description', $request->input('error')),
                Response::HTTP_BAD_REQUEST,
                'invalid_request'
            );
        }

        if (! $request->filled('code')) {
            return $this->callbackErrorResponse(
                $request,
                $versioned,
                'Missing WorkOS authorization code.',
                Response::HTTP_BAD_REQUEST,
                'invalid_request'
            );
        }

        $state = (string) $request->input('state');
        $sessionState = (string) $request->session()->get('workos_state');
        $cookieState = (string) $request->cookie(self::STATE_COOKIE);
        $stateIsValid = (filled($sessionState) && hash_equals($sessionState, $state))
            || (filled($cookieState) && hash_equals($cookieState, $state));

        if (! $stateIsValid) {
            // React StrictMode mounts effects twice in development. If the first
            // callback already completed, return the resulting session instead
            // of attempting to exchange the one-time WorkOS code again.
            if ($request->user() && hash_equals(
                (string) $request->session()->get('workos_completed_state'),
                $state
            )) {
                return $versioned
                    ? $this->versionedAuthenticatedResponse($request)
                    : $this->authenticatedResponse($request);
            }

            return $this->callbackErrorResponse(
                $request,
                $versioned,
                'Invalid WorkOS state.',
                Response::HTTP_BAD_REQUEST,
                'invalid_request'
            );
        }

        $authentication = $this->workOs->authenticateWithCode(
            $request->input('code'),
            $request->ip(),
            $request->userAgent()
        );

        if (! filled($authentication['user']['email'] ?? null)) {
            return $this->callbackErrorResponse(
                $request,
                $versioned,
                'WorkOS did not return a user email.',
                $versioned ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_BAD_REQUEST,
                'validation_failed'
            );
        }

        $user = $this->syncUser($authentication);

        Auth::login($user);

        $request->session()->regenerate();
        $request->session()->forget('workos_state');
        $request->session()->put([
            'workos_completed_state' => $state,
            'workos_session_id' => $authentication['session_id'] ?? null,
            'workos_organization_id' => $authentication['organization_id'] ?? null,
        ]);

        Cookie::queue(Cookie::forget(self::STATE_COOKIE));

        return $versioned
            ? $this->versionedAuthenticatedResponse($request)
            : $this->authenticatedResponse($request);
    }

    public function user(Request $request): JsonResponse
    {
        return $this->authenticatedResponse($request);
    }

    private function authenticatedResponse(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
            'workos_organization_id' => $request->session()->get('workos_organization_id'),
        ]);
    }

    private function versionedAuthenticatedResponse(Request $request): JsonResponse
    {
        $user = $request->user();
        $now = now()->toJSON();
        $frontendOrigin = $this->frontendOrigin();
        $applicationId = (string) config('services.latte.application_id');
        $organizationId = (string) config('services.latte.organization_id');
        $email = (string) $user->getAttribute('email');
        $name = (string) ($user->getAttribute('name') ?: $email);
        $role = ((int) $user->getAttribute('user_type_id')) === 1
            ? 'organization_administrator'
            : 'organization_user';
        $userId = $this->versionedUserId($user);
        $membershipId = $this->versionedMembershipId($organizationId, $user);
        $requestId = $this->requestId($request);

        return response()->json([
            'data' => [
                'mode' => 'latte',
                'user' => [
                    'id' => $userId,
                    'type' => 'user',
                    'attributes' => [
                        'email' => $email,
                        'name' => $name,
                    ],
                ],
                'application' => [
                    'id' => $applicationId,
                    'type' => 'application',
                    'attributes' => [
                        'kind' => 'latte',
                        'name' => config('app.name', 'Latte'),
                        'trusted_origin' => $frontendOrigin,
                        'redirect_uris' => [$frontendOrigin.'/auth/callback'],
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ],
                'organization' => [
                    'id' => $organizationId,
                    'type' => 'organization',
                    'attributes' => [
                        'name' => 'Latte Local',
                        'slug' => 'latte-local',
                        'status' => 'active',
                        'database_limit' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ],
                'membership' => [
                    'id' => $membershipId,
                    'type' => 'membership',
                    'attributes' => [
                        'role' => $role,
                        'status' => 'active',
                        'created_at' => optional($user->getAttribute('created_at'))->toJSON() ?: $now,
                        'updated_at' => optional($user->getAttribute('updated_at'))->toJSON() ?: $now,
                    ],
                ],
            ],
            'meta' => ['request_id' => $requestId],
        ])->header('X-Request-Id', $requestId);
    }

    private function versionedUserId(User $user): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'pane:user:'.$user->getKey());
    }

    private function versionedMembershipId(string $organizationId, User $user): string
    {
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'pane:membership:'.$organizationId.':'.$user->getKey()
        );
    }

    private function versionedErrorResponse(Request $request, string $code, string $message, int $status): JsonResponse
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

    private function callbackErrorResponse(
        Request $request,
        bool $versioned,
        string $message,
        int $status,
        string $code
    ): JsonResponse
    {
        if ($versioned) {
            return $this->versionedErrorResponse($request, $code, $message, $status);
        }

        return response()->json(['message' => $message], $status);
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid();
    }

    private function versionedNoContentResponse(Request $request): Response
    {
        return response()->noContent()
            ->header('X-Request-Id', $this->requestId($request));
    }

    private function frontendOrigin(): string
    {
        return $this->originFromUrl((string) config('services.latte.frontend_url'))
            ?? 'https://latte.localhost';
    }

    private function originFromUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        $port = $this->originPort($parts);
        $defaultPort = $this->originPort(['scheme' => $scheme]);

        return $scheme.'://'.$host.($port !== null && $port !== $defaultPort ? ':'.$port : '');
    }

    private function intendedRedirectUrl(Request $request): string
    {
        $fallback = config('services.workos.return_to') ?: url('/');
        $redirectTo = $request->input('redirect_to', $request->query('redirect_to'));

        if (! is_string($redirectTo) || blank($redirectTo)) {
            return $fallback;
        }

        if (str_starts_with($redirectTo, '/') && ! str_starts_with($redirectTo, '//')) {
            return $redirectTo;
        }

        if (! filter_var($redirectTo, FILTER_VALIDATE_URL)) {
            return $fallback;
        }

        $allowedOrigins = array_filter(array_merge([
            config('services.workos.return_to'),
            config('app.url'),
        ], config('cors.allowed_origins', [])));

        foreach ($allowedOrigins as $allowedOrigin) {
            if ($this->sameOrigin($redirectTo, $allowedOrigin)) {
                return $redirectTo;
            }
        }

        return $fallback;
    }

    private function versionedIntendedRedirectUrl(Request $request): string|JsonResponse
    {
        $redirectTo = $request->input('redirect_to');

        if (! is_string($redirectTo) || blank($redirectTo)) {
            return $this->versionedErrorResponse(
                $request,
                'validation_failed',
                'The redirect_to field is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (! $this->isValidRedirectUrl($redirectTo)) {
            return $this->versionedErrorResponse(
                $request,
                'validation_failed',
                'The redirect_to field must be a valid redirect URI.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (! $this->isAllowedRedirectUrl($redirectTo)) {
            return $this->versionedErrorResponse(
                $request,
                'redirect_not_allowed',
                'The redirect_to URL is not allowed.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $redirectTo;
    }

    private function isValidRedirectUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return $scheme === 'https'
            || in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    private function isAllowedRedirectUrl(string $redirectTo): bool
    {
        $allowedOrigins = array_filter(array_merge([
            config('services.latte.frontend_url'),
            config('services.workos.return_to'),
            config('app.url'),
        ], config('cors.allowed_origins', [])));

        foreach ($allowedOrigins as $allowedOrigin) {
            if ($this->sameOrigin($redirectTo, $allowedOrigin)) {
                return true;
            }
        }

        return false;
    }

    private function sameOrigin(string $url, string $allowedOrigin): bool
    {
        $urlParts = parse_url($url);
        $allowedParts = parse_url($allowedOrigin);

        if (! isset($urlParts['scheme'], $urlParts['host'], $allowedParts['scheme'], $allowedParts['host'])) {
            return false;
        }

        return strtolower($urlParts['scheme']) === strtolower($allowedParts['scheme'])
            && strtolower($urlParts['host']) === strtolower($allowedParts['host'])
            && $this->originPort($urlParts) === $this->originPort($allowedParts);
    }

    /**
     * @param  array{scheme?: string, port?: int}  $parts
     */
    private function originPort(array $parts): ?int
    {
        if (isset($parts['port'])) {
            return $parts['port'];
        }

        return match (strtolower($parts['scheme'] ?? '')) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $authentication
     */
    private function syncUser(array $authentication): User
    {
        $workOsUser = $authentication['user'] ?? [];
        $email = $workOsUser['email'];

        $details = array_filter([
            'workos' => [
                'first_name' => $workOsUser['first_name'] ?? null,
                'last_name' => $workOsUser['last_name'] ?? null,
                'profile_picture_url' => $workOsUser['profile_picture_url'] ?? null,
                'external_id' => $workOsUser['external_id'] ?? null,
                'authentication_method' => $authentication['authentication_method'] ?? null,
            ],
        ]);

        $user = User::query()
            ->when($workOsUser['id'] ?? null, fn ($query, $workOsId) => $query->where('workos_id', $workOsId))
            ->orWhere('email', $email)
            ->first();

        if (! $user) {
            $user = new User([
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        $user->forceFill([
            'user_type_id' => $user->user_type_id ?: 2,
            'name' => $email,
            'email' => $email,
            'email_verified_at' => ($workOsUser['email_verified'] ?? false) ? now() : $user->email_verified_at,
            'workos_id' => $workOsUser['id'] ?? $user->workos_id,
            'workos_organization_id' => $authentication['organization_id'] ?? null,
            'details' => array_replace_recursive($user->details ?? [], $details),
            'is_active' => true,
            'last_login_at' => now(),
        ])->save();

        return $user;
    }
}
