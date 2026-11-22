<?php

namespace App\Http\Middleware;

use App\Sso\Api\ApiResponse;
use App\Sso\OAuth\Scopes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The admin API needs both the sso.admin scope on the token and a role the account holds right now. */
class RequireSsoAdmin
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Roles come from the database on every call, not from the token, so a removed role stops working at once.
        if (! $user || ! $user->tokenCan(Scopes::ADMIN) || ! $user->hasRole(...$roles)) {
            return ApiResponse::error('FORBIDDEN', "You don't have access to this.", 403);
        }

        return $next($request);
    }
}
