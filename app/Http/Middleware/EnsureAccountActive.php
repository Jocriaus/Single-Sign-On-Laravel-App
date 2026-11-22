<?php

namespace App\Http\Middleware;

use App\Sso\Accounts\UserClaims;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/** A valid token is not enough: the account must still be allowed to use apps. */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user === null || UserClaims::inactiveReason($user) !== null) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
