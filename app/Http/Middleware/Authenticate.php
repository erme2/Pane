<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * An inactive user (deactivated, or anonymised) loses access on the next request, whatever
     * session they still hold, the same way the login callback already refuses them.
     */
    protected function authenticate($request, array $guards): void
    {
        parent::authenticate($request, $guards);

        // The guard that authenticated the request (the parent made it the default).
        $auth = $this->auth->guard();
        $user = $auth->user();

        if ($user !== null && isset($user->is_active) && ! (bool) $user->is_active) {
            // A bearer-token guard has no session to end: the device guard already refuses inactive users.
            if (method_exists($auth, 'logout')) {
                $auth->logout();
            }

            $this->unauthenticated($request, $guards);
        }
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }
}
