<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Sso\Accounts\Lockout;
use App\Sso\Activity;
use App\Sso\LocalRedirect;
use App\Sso\Logout\BackChannelLogout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    private const DUMMY_HASH = '$2y$12$zFzP676WtdWS3jIU913BgecX4q9ZoRQp3JavacjXkpSpDvgZf8G5e';

    private const GENERIC = 'Wrong username or password.';

    public function show(Request $request)
    {
        if (Auth::check()) {
            return redirect('/');
        }

        return view('login', [
            'appName' => $this->intendedAppName($request),
            'error' => session('error'),
            'info' => $request->query->has('logout') ? "You've signed out." : null,
        ]);
    }

    public function attempt(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::findByUsername($data['username']);

        // Hash against a dummy when the user is unknown so timing doesn't reveal which usernames exist.
        $passwordOk = Hash::check($data['password'], $user ? $user->password : self::DUMMY_HASH);

        if (! $user || ! $passwordOk) {
            if ($user && ! Lockout::isLocked($user)) {
                Lockout::recordFailure($user);
            }
            Activity::log($user, 'Failed sign-in for "'.Str::limit(trim($data['username']), 100, '').'"', Activity::AUTH);

            return $this->back('Wrong username or password.', $data['username']);
        }

        if (Lockout::isLocked($user)) {
            return $this->back('Too many failed attempts. Try again after '.config('sso.lockout.minutes').' minutes.', $data['username']);
        }

        if (! $user->status_flag) {
            return $this->back('This account is disabled. Contact the system administrator.', $data['username']);
        }

        Lockout::clear($user);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'sso.sid' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'sso.user_id' => $user->getKey(),
            'sso.username' => $user->username,
            'sso.auth_time' => time(),
        ]);
        Activity::log($user, 'Signed in', Activity::AUTH);

        return LocalRedirect::intended();
    }

    /** Signs out of the SSO and of every app signed in from this browser session. */
    public function logout(Request $request, BackChannelLogout $logout): RedirectResponse
    {
        $user = $request->user();
        $sid = $request->session()->get('sso.sid');
        if ($user !== null && is_string($sid) && $sid !== '') {
            $logout->signOutSession($user, $sid);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login?logout');
    }

    private function back(string $error, string $username): RedirectResponse
    {
        return redirect()->route('login')->with('error', $error)->withInput(['username' => $username]);
    }

    private function intendedAppName(Request $request): ?string
    {
        $intended = $request->session()->get('url.intended');
        if (! $intended || ! str_contains((string) parse_url($intended, PHP_URL_PATH), '/oauth2/authorize')) {
            return null;
        }
        parse_str((string) parse_url($intended, PHP_URL_QUERY), $query);
        $id = $query['client_id'] ?? null;

        return Client::findById($id)?->name;
    }
}
