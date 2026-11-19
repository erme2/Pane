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

        foreach ($guards ?: [null] as $guard) {
            $auth = $this->auth->guard($guard);
            $user = $auth->user();

            if ($user !== null && isset($user->is_active) && ! (bool) $user->is_active) {
                $auth->logout();

                $this->unauthenticated($request, $guards);
            }
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
