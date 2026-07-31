<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ApplicationRegistration;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\OrganizationInvitationService;
use App\Services\PaneAdminLifecycleService;
use App\Services\WorkOsService;
use App\Support\LatteApplicationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

class WorkOsAuthController extends Controller
{
    private const STATE_COOKIE = 'pane_workos_state';

    private const V1_APPLICATION_SESSION_KEY = 'pane_v1_application_id';

    private const V1_APPLICATION_SESSION_VERSION_KEY = 'pane_v1_application_session_version';

    private const PANE_ADMIN_INVITATION_TOKEN_HASH_SESSION_KEY = 'pane_admin_invitation_token_hash';

    public function __construct(
        private readonly WorkOsService $workOs,
        private readonly PaneAdminLifecycleService $administrators,
        private readonly OrganizationInvitationService $organizationInvitations,
        private readonly ApplicationRegistryService $applications,
    ) {}

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

    public function destroySession(Request $request): Response|JsonResponse
    {
        $application = $this->activeSessionApplication($request);

        if ($application instanceof JsonResponse) {
            return $application;
        }

        Auth::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->versionedNoContentResponse($request);
    }

    private function loginIntentResponse(Request $request, bool $versioned): JsonResponse
    {
        $application = $versioned ? $this->requestApplication($request) : null;

        if ($application instanceof JsonResponse) {
            return $application;
        }

        $intendedRedirectUrl = $versioned
            ? $this->versionedIntendedRedirectUrl($request, $application)
            : $this->intendedRedirectUrl($request);

        if ($intendedRedirectUrl instanceof JsonResponse) {
            return $intendedRedirectUrl;
        }

        $invitationTokenHash = $versioned ? $this->versionedInvitationTokenHash($request) : null;

        if ($invitationTokenHash instanceof JsonResponse) {
            return $invitationTokenHash;
        }

        $this->workOs->ensureConfigured();

        $state = $this->workOs->makeState();

        $request->session()->put('workos_state', $state);
        $request->session()->put('workos_intended_url', $intendedRedirectUrl);

        if ($versioned) {
            $request->session()->put(self::V1_APPLICATION_SESSION_KEY, $application->getKey());
            $request->session()->put(self::V1_APPLICATION_SESSION_VERSION_KEY, $application->session_version);

            if (is_string($invitationTokenHash)) {
                $request->session()->put(self::PANE_ADMIN_INVITATION_TOKEN_HASH_SESSION_KEY, $invitationTokenHash);
            } else {
                $request->session()->forget(self::PANE_ADMIN_INVITATION_TOKEN_HASH_SESSION_KEY);
            }
        }

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
                $this->providerCallbackErrorMessage($request, $versioned),
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

        $invitationTokenHash = $versioned
            ? $request->session()->get(self::PANE_ADMIN_INVITATION_TOKEN_HASH_SESSION_KEY)
            : null;
        $workOsUser = is_array($authentication['user'] ?? null) ? $authentication['user'] : [];

        try {
            $user = $versioned
                ? $this->syncVersionedUser($request, $authentication, $workOsUser, $invitationTokenHash)
                : $this->syncUser($authentication);
        } catch (InvalidArgumentException $exception) {
            $request->session()->forget(self::PANE_ADMIN_INVITATION_TOKEN_HASH_SESSION_KEY);

            return $this->callbackErrorResponse(
                $request,
                $versioned,
                $exception->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $this->invitationErrorCode($exception)
            );
        }

        if (! (bool) $user->is_active) {
            return $this->callbackErrorResponse(
                $request,
                $versioned,
                'Pane account is inactive.',
                Response::HTTP_FORBIDDEN,
                'permission_denied'
            );
        }

        Auth::login($user);

        $request->session()->regenerate();
        $request->session()->forget([
            'workos_state',
            self::PANE_ADMIN_INVITATION_TOKEN_HASH_SESSION_KEY,
        ]);
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
        $application = $this->activeSessionApplication($request);

        if ($application instanceof JsonResponse) {
            return $application;
        }

        if ($application->isBurro()) {
            if (! $user instanceof User || ! $user->isPaneAdministrator()) {
                return $this->versionedErrorResponse(
                    $request,
                    'permission_denied',
                    'Only active Pane administrators can access Burro.',
                    Response::HTTP_FORBIDDEN
                );
            }

            $requestId = $this->requestId($request);

            return response()->json([
                'data' => [
                    'mode' => 'burro_installation',
                    'user' => $this->userResource($user),
                    'application' => $this->applicationResource($application),
                ],
                'meta' => ['request_id' => $requestId],
            ])->header('X-Request-Id', $requestId);
        }

        $organization = $this->applications->fixedOrganizationFor($application);

        if (! $organization instanceof Organization || ! $organization->isActive()) {
            return $this->versionedErrorResponse(
                $request,
                'organization_inactive',
                'The application organization is inactive.',
                Response::HTTP_FORBIDDEN
            );
        }

        $membership = $organization->activeMembershipFor($user);
        $requestId = $this->requestId($request);

        if (! $membership instanceof OrganizationMembership && ! $user->isPaneAdministrator()) {
            return $this->versionedErrorResponse(
                $request,
                'membership_required',
                'An active organization membership is required.',
                Response::HTTP_FORBIDDEN
            );
        }

        return response()->json([
            'data' => [
                'mode' => 'latte',
                'user' => $this->userResource($user),
                'application' => $this->applicationResource($application, true),
                'organization' => $this->organizationResource($organization),
                'membership' => $membership instanceof OrganizationMembership
                    ? $this->membershipResource($membership)
                    : $this->syntheticPaneAdminMembershipResource($organization, $user, $now),
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

    private function activeSessionApplication(Request $request): ApplicationRegistration|JsonResponse
    {
        $sessionApplicationId = $request->session()->get(self::V1_APPLICATION_SESSION_KEY);

        if (! is_string($sessionApplicationId)) {
            return $this->versionedErrorResponse(
                $request,
                'application_not_allowed',
                'The application origin is not allowed.',
                Response::HTTP_FORBIDDEN
            );
        }

        $application = $this->applications->activeApplicationForSession(
            $sessionApplicationId,
            $request->session()->get(self::V1_APPLICATION_SESSION_VERSION_KEY)
        );

        if (! $application instanceof ApplicationRegistration) {
            return $this->versionedErrorResponse(
                $request,
                'application_not_allowed',
                'The application origin is not allowed.',
                Response::HTTP_FORBIDDEN
            );
        }

        return $application;
    }

    private function requestApplication(Request $request): ApplicationRegistration|JsonResponse
    {
        $origin = $request->header('Origin');

        if (! is_string($origin)) {
            return $this->versionedErrorResponse(
                $request,
                'application_not_allowed',
                'The application origin is not allowed.',
                Response::HTTP_FORBIDDEN
            );
        }

        $application = $this->applications->activeApplicationForOrigin($origin);

        return $application instanceof ApplicationRegistration
            ? $application
            : $this->versionedErrorResponse(
                $request,
                'application_not_allowed',
                'The application origin is not allowed.',
                Response::HTTP_FORBIDDEN
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
    ): JsonResponse {
        if ($versioned) {
            return $this->versionedErrorResponse($request, $code, $message, $status);
        }

        return response()->json(['message' => $message], $status);
    }

    private function providerCallbackErrorMessage(Request $request, bool $versioned): string
    {
        if ($versioned) {
            return 'The WorkOS callback was rejected.';
        }

        return (string) $request->input('error_description', $request->input('error'));
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

    private function versionedIntendedRedirectUrl(Request $request, ApplicationRegistration $application): string|JsonResponse
    {
        $body = $this->requestBody($request);
        $unsupportedFields = array_values(array_diff(array_keys($body), ['redirect_to', 'invitation_token']));

        if ($unsupportedFields !== []) {
            sort($unsupportedFields);

            return $this->versionedErrorResponse(
                $request,
                'validation_failed',
                'The '.$unsupportedFields[0].' field is not supported.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $redirectTo = $body['redirect_to'] ?? null;

        if (! is_string($redirectTo) || blank($redirectTo)) {
            return $this->versionedErrorResponse(
                $request,
                'validation_failed',
                'The redirect_to field is required.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $normalizedRedirectTo = LatteApplicationConfig::normalizeRedirectUri($redirectTo);

        if ($normalizedRedirectTo === null) {
            return $this->versionedErrorResponse(
                $request,
                'validation_failed',
                'The redirect_to field must be a valid redirect URI.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        if (! in_array($normalizedRedirectTo, $application->redirect_uris ?? [], true)) {
            return $this->versionedErrorResponse(
                $request,
                'redirect_not_allowed',
                'The redirect_to URL is not allowed.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return $normalizedRedirectTo;
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, string>}
     */
    private function userResource(User $user): array
    {
        $email = (string) $user->getAttribute('email');

        return [
            'id' => $this->versionedUserId($user),
            'type' => 'user',
            'attributes' => [
                'email' => $email,
                'name' => (string) ($user->getAttribute('name') ?: $email),
            ],
        ];
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function applicationResource(ApplicationRegistration $application, bool $sessionLatte = false): array
    {
        $attributes = [
            'kind' => $application->kind,
            'name' => $application->name,
            'trusted_origin' => $application->trusted_origin,
            'redirect_uris' => array_values($application->redirect_uris ?? []),
            'status' => $application->status,
            'created_at' => $application->created_at?->toJSON() ?: now()->toJSON(),
            'updated_at' => $application->updated_at?->toJSON() ?: now()->toJSON(),
        ];

        if (! $sessionLatte) {
            $attributes['organization_id'] = $application->isLatte() ? $application->organization_id : null;
        }

        return [
            'id' => (string) $application->getKey(),
            'type' => 'application',
            'attributes' => $attributes,
        ];
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function organizationResource(Organization $organization): array
    {
        return [
            'id' => (string) $organization->getKey(),
            'type' => 'organization',
            'attributes' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status,
                'database_limit' => $organization->database_limit,
                'created_at' => $organization->created_at?->toJSON(),
                'updated_at' => $organization->updated_at?->toJSON(),
            ],
        ];
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function membershipResource(OrganizationMembership $membership): array
    {
        return [
            'id' => (string) $membership->getKey(),
            'type' => 'membership',
            'attributes' => [
                'role' => $membership->role,
                'status' => $membership->status,
                'created_at' => $membership->created_at?->toJSON(),
                'updated_at' => $membership->updated_at?->toJSON(),
            ],
        ];
    }

    /**
     * @return array{id: string, type: string, attributes: array<string, mixed>}
     */
    private function syntheticPaneAdminMembershipResource(Organization $organization, User $user, string $now): array
    {
        return [
            'id' => $this->versionedMembershipId((string) $organization->getKey(), $user),
            'type' => 'membership',
            'attributes' => [
                'role' => $user->isPaneAdministrator() ? 'organization_administrator' : 'organization_user',
                'status' => 'active',
                'created_at' => optional($user->getAttribute('created_at'))->toJSON() ?: $now,
                'updated_at' => optional($user->getAttribute('updated_at'))->toJSON() ?: $now,
            ],
        ];
    }

    private function versionedInvitationTokenHash(Request $request): string|JsonResponse|null
    {
        $body = $this->requestBody($request);
        $token = $body['invitation_token'] ?? null;

        if ($token === null) {
            return null;
        }

        if (! is_string($token) || blank($token) || strlen($token) > 255) {
            return $this->versionedErrorResponse(
                $request,
                'validation_failed',
                'The invitation_token field must be a valid invitation token.',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return hash('sha256', $token);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(Request $request): array
    {
        return $request->isJson()
            ? $request->json()->all()
            : $request->request->all();
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

        $details = [
            'workos' => [
                'first_name' => $workOsUser['first_name'] ?? null,
                'last_name' => $workOsUser['last_name'] ?? null,
                'profile_picture_url' => $workOsUser['profile_picture_url'] ?? null,
                'external_id' => $workOsUser['external_id'] ?? null,
                'authentication_method' => $authentication['authentication_method'] ?? null,
            ],
        ];

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
            'user_type_id' => $user->user_type_id ?: User::STANDARD_USER_TYPE_ID,
            'name' => $email,
            'email' => $email,
            'email_verified_at' => ($workOsUser['email_verified'] ?? false) ? now() : $user->email_verified_at,
            'workos_id' => $workOsUser['id'] ?? $user->workos_id,
            'workos_organization_id' => $authentication['organization_id'] ?? null,
            'details' => array_replace_recursive($user->details ?? [], $details),
            'is_active' => $this->shouldActivateSyncedUser($user),
            'last_login_at' => now(),
        ])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $authentication
     * @param  array<string, mixed>  $workOsUser
     */
    private function syncVersionedUser(
        Request $request,
        array $authentication,
        array $workOsUser,
        mixed $invitationTokenHash
    ): User {
        $application = $this->activeSessionApplication($request);

        if ($application instanceof JsonResponse) {
            throw new InvalidArgumentException('The application origin is not allowed.');
        }

        if (is_string($invitationTokenHash)) {
            return $this->acceptVersionedInvitation($application, $invitationTokenHash, $workOsUser, $authentication);
        }

        return DB::transaction(function () use ($application, $authentication): User {
            $user = $this->existingVersionedUser($authentication);

            $this->assertVersionedUserCanActivate($user);

            if ($application->isBurro()) {
                if (! $user->isPaneAdministrator()) {
                    throw new InvalidArgumentException('Only active Pane administrators can access Burro.');
                }

                return $this->syncExistingVersionedUser($user, $authentication);
            }

            $organization = $this->applications->fixedOrganizationFor($application);

            if (! $organization instanceof Organization || ! $organization->isActive()) {
                throw new InvalidArgumentException('The application organization is inactive.');
            }

            if (! $user->isPaneAdministrator() && ! $organization->activeMembershipFor($user) instanceof OrganizationMembership) {
                throw new InvalidArgumentException('An active organization membership or invitation is required.');
            }

            return $this->syncExistingVersionedUser($user, $authentication);
        });
    }

    /**
     * @param  array<string, mixed>  $workOsUser
     * @param  array<string, mixed>  $authentication
     */
    private function acceptVersionedInvitation(
        ApplicationRegistration $application,
        string $tokenHash,
        array $workOsUser,
        array $authentication
    ): User {
        $organization = $this->applications->fixedOrganizationFor($application);

        if (
            $application->isLatte()
            && $organization instanceof Organization
            && $this->organizationInvitations->hasOrganizationInvitationHash($organization, $tokenHash)
        ) {
            return $this->organizationInvitations->acceptOrganizationInvitationHash(
                $organization,
                $tokenHash,
                $workOsUser,
                $authentication
            );
        }

        return $this->administrators->acceptPaneAdministratorInvitationHash(
            $tokenHash,
            $workOsUser,
            $authentication
        );
    }

    /**
     * @param  array<string, mixed>  $authentication
     */
    private function existingVersionedUser(array $authentication): User
    {
        $workOsUser = $authentication['user'] ?? [];
        $email = $this->normalizeEmail((string) ($workOsUser['email'] ?? ''));

        $user = User::query()
            ->where(function ($query) use ($workOsUser, $email): void {
                if (filled($workOsUser['id'] ?? null)) {
                    $query->where('workos_id', $workOsUser['id'])
                        ->orWhere('email', $email);

                    return;
                }

                $query->where('email', $email);
            })
            ->lockForUpdate()
            ->first();

        if (! $user instanceof User) {
            throw new InvalidArgumentException('An active organization membership or invitation is required.');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $authentication
     */
    private function syncExistingVersionedUser(User $user, array $authentication): User
    {
        $workOsUser = $authentication['user'] ?? [];
        $email = $this->normalizeEmail((string) ($workOsUser['email'] ?? ''));
        $details = array_filter([
            'workos' => [
                'first_name' => $workOsUser['first_name'] ?? null,
                'last_name' => $workOsUser['last_name'] ?? null,
                'profile_picture_url' => $workOsUser['profile_picture_url'] ?? null,
                'external_id' => $workOsUser['external_id'] ?? null,
                'authentication_method' => $authentication['authentication_method'] ?? null,
            ],
        ]);

        $user->forceFill([
            'name' => $this->workOsDisplayName($workOsUser, $user->name ?: $email),
            'email' => $email,
            'email_verified_at' => ($workOsUser['email_verified'] ?? false) ? now() : $user->email_verified_at,
            'workos_id' => $workOsUser['id'] ?? $user->workos_id,
            'workos_organization_id' => $authentication['organization_id'] ?? null,
            'details' => array_replace_recursive($user->details ?? [], $details),
            'is_active' => $this->shouldActivateSyncedUser($user),
            'last_login_at' => now(),
        ])->save();

        return $user;
    }

    private function shouldActivateSyncedUser(User $user): bool
    {
        if (
            $user->exists
            && (int) $user->user_type_id === User::PANE_ADMINISTRATOR_USER_TYPE_ID
            && ! (bool) $user->is_active
        ) {
            return false;
        }

        return true;
    }

    private function assertVersionedUserCanActivate(User $user): void
    {
        if (
            (int) $user->user_type_id === User::PANE_ADMINISTRATOR_USER_TYPE_ID
            && ! (bool) $user->is_active
        ) {
            throw new InvalidArgumentException('Pane account is inactive.');
        }
    }

    private function invitationErrorCode(InvalidArgumentException $exception): string
    {
        return match ($exception->getMessage()) {
            'Pane administrator invitation has expired.' => 'invitation_expired',
            'Organization invitation has expired.' => 'invitation_expired',
            'Pane administrator invitation was revoked.' => 'invitation_revoked',
            'Organization invitation was revoked.' => 'invitation_revoked',
            'Pane administrator invitation was already accepted.' => 'invitation_already_accepted',
            'Organization invitation was already accepted.' => 'invitation_already_accepted',
            'Pane administrator invitation email does not match the WorkOS identity.' => 'invitation_email_mismatch',
            'Organization invitation email does not match the WorkOS identity.' => 'invitation_email_mismatch',
            'Organization invitation requires a verified WorkOS email.' => 'invitation_email_unverified',
            'An active organization membership or invitation is required.' => 'membership_required',
            'Only active Pane administrators can access Burro.' => 'permission_denied',
            'Pane account is inactive.' => 'permission_denied',
            'The application organization is inactive.' => 'organization_inactive',
            'The application origin is not allowed.' => 'application_not_allowed',
            'Organization membership already exists.' => 'operation_conflict',
            default => 'invitation_invalid',
        };
    }

    private function normalizeEmail(string $email): string
    {
        $normalized = strtolower(trim($email));

        if (! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('WorkOS did not return a user email.');
        }

        return $normalized;
    }

    private function workOsDisplayName(array $workOsUser, string $fallback): string
    {
        $name = trim(implode(' ', array_filter([
            $workOsUser['first_name'] ?? null,
            $workOsUser['last_name'] ?? null,
        ])));

        return $name !== '' ? $name : $fallback;
    }
}
