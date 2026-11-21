<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Sso\Accounts\Lockout;
use App\Sso\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Client;

class LoginController extends Controller
{
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

        if (! $user || ! Hash::check($data['password'], $user->password)) {
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

        $intended = $request->session()->get('url.intended');
        if ($intended && parse_url($intended, PHP_URL_HOST) !== null
            && parse_url($intended, PHP_URL_HOST) !== $request->getHost()) {
            $request->session()->forget('url.intended');
        }

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
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

        return is_string($id) && $id !== '' ? Client::query()->whereKey($id)->value('name') : null;
    }
}
