<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    private const ALLOWED = [
        'change-password*', 'login*', 'logout*', 'connect/logout*',
        '.well-known/*', 'oauth2/jwks*', 'actuator/health*', 'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password || $request->is(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect('/change-password');
    }
}
