<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WorkOsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Http\Response;

class WorkOsAuthController extends Controller
{
    public function __construct(private readonly WorkOsService $workOs)
    {
    }

    public function login(Request $request): RedirectResponse
    {
        $this->workOs->ensureConfigured();

        $state = $this->workOs->makeState();

        $request->session()->put('workos_state', $state);
        $request->session()->put('workos_intended_url', $request->query('redirect_to', url('/')));

        return redirect()->away($this->workOs->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse|Response
    {
        if ($request->has('error')) {
            return redirect('/')
                ->withErrors(['workos' => $request->query('error_description', $request->query('error'))]);
        }

        if (! $request->filled('code')) {
            return response('Missing WorkOS authorization code.', Response::HTTP_BAD_REQUEST);
        }

        if (! hash_equals((string) $request->session()->pull('workos_state'), (string) $request->query('state'))) {
            return response('Invalid WorkOS state.', Response::HTTP_BAD_REQUEST);
        }

        $authentication = $this->workOs->authenticateWithCode(
            $request->query('code'),
            $request->ip(),
            $request->userAgent()
        );

        if (! filled($authentication['user']['email'] ?? null)) {
            return response('WorkOS did not return a user email.', Response::HTTP_BAD_REQUEST);
        }

        $user = $this->syncUser($authentication);

        Auth::login($user);

        $request->session()->regenerate();
        $request->session()->put('workos_access_token', $authentication['access_token'] ?? null);
        $request->session()->put('workos_refresh_token', $authentication['refresh_token'] ?? null);
        $request->session()->put('workos_session_id', $authentication['session_id'] ?? null);
        $request->session()->put('workos_organization_id', $authentication['organization_id'] ?? null);

        return redirect()->to($request->session()->pull('workos_intended_url', url('/')));
    }

    public function logout(Request $request): RedirectResponse
    {
        $sessionId = $request->session()->pull('workos_session_id');

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away($this->workOs->logoutUrl($sessionId));
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
            'workos_organization_id' => $request->session()->get('workos_organization_id'),
        ]);
    }

    /**
     * @param array<string, mixed> $authentication
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
