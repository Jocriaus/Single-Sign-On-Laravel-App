<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A browser session belongs to the account that typed its password. If that account's id or username no
 * longer matches what was recorded at sign-in (a username can be given to someone else), or the account has
 * been disabled, the session ends.
 */
class EnsureSessionOwner
{
    /** Signing out ends the session anyway, and also signs out the apps of the session. */
    private const EXEMPT = ['logout', 'connect/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || $request->is(...self::EXEMPT) || self::isOwner($request, $user)) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->guest(route('login'));
    }

    /** Also used by AuthorizeController, which is exempt from this middleware (it must answer the app itself). */
    public static function isOwner(Request $request, Authenticatable $user): bool
    {
        $session = $request->session();
        $username = $session->get('sso.username');

        return $user instanceof User
            && $user->status_flag
            && (string) $session->get('sso.user_id') === (string) $user->getKey()
            && is_string($username)
            && mb_strtolower(trim($username)) === mb_strtolower(trim((string) $user->username));
    }
}
