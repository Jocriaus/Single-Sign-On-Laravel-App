<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Sso\Logout\BackChannelLogout;
use App\Sso\OAuth\SigningKey;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * RP-initiated logout: an app sends the browser here to sign the user out of the SSO and of every app
 * signed in from this browser session.
 */
class EndSessionController extends Controller
{
    public function __invoke(Request $request, BackChannelLogout $logout, SigningKey $key): RedirectResponse
    {
        $client = $this->appFromHint($request, $key);

        if (($user = $request->user()) !== null) {
            $sid = $request->session()->get('sso.sid');
            if (is_string($sid) && $sid !== '') {
                $logout->signOutSession($user, $sid);
            }
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->afterLogout($request, $client);
    }

    /** The app the ID token hint was issued to (expired or not); null when it is missing or doesn't verify. */
    private function appFromHint(Request $request, SigningKey $key): ?Client
    {
        $hint = $request->input('id_token_hint');
        if (! is_string($hint) || $hint === '') {
            return null;
        }

        try {
            $aud = $key->decode($hint, allowExpired: true)['aud'] ?? null;
        } catch (UnexpectedValueException|DomainException|InvalidArgumentException) {
            return null;
        }

        $clientId = $request->input('client_id');
        if (! is_string($aud) || ($clientId !== null && $clientId !== $aud)) {
            return null;
        }

        return Client::findById($aud);
    }

    /** Back to the app only at an address it registered for this; anywhere else, the login page. */
    private function afterLogout(Request $request, ?Client $client): RedirectResponse
    {
        $uri = $request->input('post_logout_redirect_uri');
        if ($client === null || ! is_string($uri) || ! in_array($uri, $client->post_logout_redirect_uris ?? [], true)) {
            return redirect('/login?logout');
        }

        $state = $request->input('state');
        if (is_string($state) && $state !== '') {
            $uri .= (str_contains($uri, '?') ? '&' : '?').http_build_query(['state' => $state], '', '&', PHP_QUERY_RFC3986);
        }

        return redirect()->away($uri);
    }
}
