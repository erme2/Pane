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

class WorkOsAuthController extends Controller
{
    private const STATE_COOKIE = 'pane_workos_state';

    public function __construct(private readonly WorkOsService $workOs) {}

    public function loginUrl(Request $request): JsonResponse
    {
        $this->workOs->ensureConfigured();

        $state = $this->workOs->makeState();

        $request->session()->put('workos_state', $state);
        $request->session()->put('workos_intended_url', $this->intendedRedirectUrl($request));

        return response()->json([
            'authorization_url' => $this->workOs->authorizationUrl($state),
            'state' => $state,
        ])->cookie(self::STATE_COOKIE, $state, 10, '/', null, false, true, false, 'lax');
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
        if ($request->filled('error')) {
            return response()->json([
                'message' => $request->input('error_description', $request->input('error')),
            ], Response::HTTP_BAD_REQUEST);
        }

        if (! $request->filled('code')) {
            return response()->json(['message' => 'Missing WorkOS authorization code.'], Response::HTTP_BAD_REQUEST);
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
                return $this->authenticatedResponse($request);
            }

            return response()->json(['message' => 'Invalid WorkOS state.'], Response::HTTP_BAD_REQUEST);
        }

        $authentication = $this->workOs->authenticateWithCode(
            $request->input('code'),
            $request->ip(),
            $request->userAgent()
        );

        if (! filled($authentication['user']['email'] ?? null)) {
            return response()->json(['message' => 'WorkOS did not return a user email.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->syncUser($authentication);

        Auth::login($user);

        $request->session()->regenerate();
        $request->session()->forget('workos_state');
        $request->session()->put([
            'workos_completed_state' => $state,
            'workos_access_token' => $authentication['access_token'] ?? null,
            'workos_refresh_token' => $authentication['refresh_token'] ?? null,
            'workos_session_id' => $authentication['session_id'] ?? null,
            'workos_organization_id' => $authentication['organization_id'] ?? null,
        ]);

        Cookie::queue(Cookie::forget(self::STATE_COOKIE));

        return $this->authenticatedResponse($request);
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

    private function intendedRedirectUrl(Request $request): string
    {
        $fallback = config('services.workos.return_to') ?: url('/');
        $redirectTo = $request->query('redirect_to');

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
