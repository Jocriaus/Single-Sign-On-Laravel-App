<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSessionOwner;
use App\Models\Client;
use App\Models\SignIn;
use App\Models\User;
use App\Sso\Accounts\UserClaims;
use App\Sso\Activity;
use App\Sso\Apps\AppAccess;
use App\Sso\OAuth\SignInContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Bridge\User as UserEntity;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs the SSO user in to an app and sends the browser back to it with an authorization code. A browser
 * that must first sign in, sign in again or change its password is sent to that page here, unless the app
 * asked for a silent sign-in (prompt=none): then the app gets the error instead.
 */
class AuthorizeController extends Controller
{
    use ConvertsPsrResponses;

    /** What makes a request "the same request" when it comes back after the password was typed again. */
    private const REAUTH_FIELDS = ['client_id', 'redirect_uri', 'scope', 'state', 'nonce', 'code_challenge', 'prompt', 'max_age'];

    public function __construct(
        private AuthorizationServer $server,
        private SignInContext $context,
    ) {
    }

    public function __invoke(Request $request, ServerRequestInterface $psrRequest, ResponseInterface $psrResponse): Response
    {
        $user = $this->sessionUser($request);
        // A POST carries the parameters as form fields; the library reads them from the query only.
        $params = $request->input();

        try {
            $authRequest = $this->server->validateAuthorizationRequest($psrRequest->withQueryParams($params));
            $client = Client::find($authRequest->getClient()->getIdentifier());
            if ($client === null) {
                // Fail closed: an app whose settings can't be read gets no sign-in.
                throw OAuthServerException::invalidClient($psrRequest);
            }

            // 1. Request objects are not supported (OpenID Connect Core 6).
            if (($params['request'] ?? null) !== null) {
                throw $this->error('request_not_supported', 'Request objects are not supported.', $authRequest);
            }
            if (($params['request_uri'] ?? null) !== null) {
                throw $this->error('request_uri_not_supported', 'request_uri is not supported.', $authRequest);
            }

            // 2. OpenID Connect, with PKCE unless the app has it switched off; the parameters read here well formed.
            $this->requireOpenIdAndPkce($authRequest, $client);
            [$prompt, $maxAge] = $this->promptAndMaxAge($params, $authRequest);
            $nonce = $this->param($params, 'nonce', $authRequest);
            $silent = in_array('none', $prompt, true);
            $login = in_array('login', $prompt, true);

            // 3. Nobody signed in.
            if ($user === null) {
                if ($silent) {
                    throw $this->error('login_required', 'The user is not signed in.', $authRequest);
                }
                if ($login || $maxAge !== null) {
                    // This sign-in is the fresh one the app asks for: no second prompt after it.
                    $this->awaitPassword($request, $params);
                }

                return $this->detour(route('login'), $request, $params);
            }

            // 4. The app wants the password typed again (max_age=0: always).
            $authTime = $request->session()->get('sso.auth_time');
            $typed = $this->pullTypedPassword($request, $params);
            $tooOld = $maxAge !== null && ($maxAge === 0 || now()->getTimestamp() - $authTime > $maxAge);
            if (! $typed && ($login || $tooOld)) {
                if ($silent) {
                    throw $this->error('login_required', 'The user must sign in again.', $authRequest);
                }
                $this->awaitPassword($request, $params);

                return $this->detour(route('login'), $request, $params);
            }

            // 5. The password must be changed first.
            if ($user->must_change_password) {
                if ($silent) {
                    throw $this->error('interaction_required', 'The user must change their password first.', $authRequest);
                }

                return $this->detour(url('/change-password'), $request, $params);
            }

            // 6. The app's access rule.
            if (! AppAccess::allows($client, $user)) {
                Activity::log($user, "Refused sign-in to app {$client->name}: no allowed role", Activity::AUTH, 'OAuth2');
                if ($silent) {
                    throw $this->backToApp(OAuthServerException::accessDenied('This account does not have access to this app.'), $authRequest);
                }

                return response()->view('no-access', [
                    'appName' => $client->name,
                    'displayName' => UserClaims::displayName($user),
                ], 403)->header('Cache-Control', 'no-store');
            }

            // 7. The code.
            $this->context->set(SignIn::create([
                'user_id' => $user->getKey(),
                'client_id' => $client->getKey(),
                'sid' => $request->session()->get('sso.sid'),
                'nonce' => $nonce,
                'auth_time' => now()->setTimestamp($authTime),
                'started_at' => now(),
            ]));
            $authRequest->setUser(new UserEntity((string) $user->getKey()));
            $authRequest->setAuthorizationApproved(true);

            return $this->convertResponse($this->server->completeAuthorizationRequest($authRequest, $psrResponse));
        } catch (OAuthServerException $e) {
            return $this->refuse($e, $psrResponse);
        }
    }

    /**
     * The signed-in user, or null. A session EnsureSessionOwner would end, or one the login page did not make,
     * ends here, and the request goes on as a guest's.
     */
    private function sessionUser(Request $request): ?User
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $session = $request->session();
        if (EnsureSessionOwner::isOwner($request, $user)
            && is_string($session->get('sso.sid')) && is_int($session->get('sso.auth_time'))) {
            return $user;
        }

        Auth::logout();
        $session->invalidate();
        $session->regenerateToken();

        return null;
    }

    /** OpenID Connect only, with PKCE (S256) unless the app has it switched off. */
    private function requireOpenIdAndPkce(AuthorizationRequestInterface $authRequest, Client $client): void
    {
        $scopes = array_map(fn (ScopeEntityInterface $scope) => $scope->getIdentifier(), $authRequest->getScopes());
        if (! in_array('openid', $scopes, true)) {
            throw $this->backToApp(OAuthServerException::invalidScope('openid'), $authRequest);
        }

        if ($authRequest->getCodeChallenge() === null) {
            if ($client->require_pkce !== false) {
                throw $this->backToApp(OAuthServerException::invalidRequest('code_challenge', 'A code_challenge (S256) is required.'), $authRequest);
            }
        } elseif ($authRequest->getCodeChallengeMethod() !== 'S256') {
            throw $this->backToApp(OAuthServerException::invalidRequest('code_challenge_method', 'Only S256 is supported.'), $authRequest);
        }
    }

    /**
     * prompt is a list of values; none goes with no other, and unknown values (consent and select_account
     * included: there is no consent screen) are ignored. max_age is a whole number of seconds.
     *
     * @return array{0: list<string>, 1: int|null}
     */
    private function promptAndMaxAge(array $params, AuthorizationRequestInterface $authRequest): array
    {
        $prompt = array_values(array_unique(preg_split('/ +/', $this->param($params, 'prompt', $authRequest) ?? '', -1, PREG_SPLIT_NO_EMPTY)));
        if (in_array('none', $prompt, true) && count($prompt) > 1) {
            throw $this->backToApp(OAuthServerException::invalidRequest('prompt', 'prompt=none cannot be combined with other values.'), $authRequest);
        }

        $maxAge = $this->param($params, 'max_age', $authRequest);
        if ($maxAge !== null && preg_match('/\A[0-9]+\z/', $maxAge) !== 1) {
            throw $this->backToApp(OAuthServerException::invalidRequest('max_age', 'max_age must be a whole number of seconds.'), $authRequest);
        }

        return [$prompt, $maxAge === null ? null : (int) $maxAge];
    }

    /** A parameter's text. Empty counts as absent (RFC 6749 section 3.1); a list where text belongs is malformed. */
    private function param(array $params, string $name, AuthorizationRequestInterface $authRequest): ?string
    {
        $value = $params[$name] ?? null;
        if ($value !== null && ! is_scalar($value)) {
            throw $this->backToApp(OAuthServerException::invalidRequest($name), $authRequest);
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Remembers that this request waits for the password to be typed. The login page marks it typed; the
     * request then goes through once (pullTypedPassword).
     */
    private function awaitPassword(Request $request, array $params): void
    {
        $request->session()->put('sso.reauth', ['request' => $this->requestHash($params), 'signed_in' => false]);
    }

    /** Whether the password was typed for this very request since it asked; the mark is used up. */
    private function pullTypedPassword(Request $request, array $params): bool
    {
        $mark = $request->session()->get('sso.reauth');
        if (! is_array($mark) || ($mark['signed_in'] ?? null) !== true || ($mark['request'] ?? null) !== $this->requestHash($params)) {
            return false;
        }
        $request->session()->forget('sso.reauth');

        return true;
    }

    private function requestHash(array $params): string
    {
        $fields = array_filter(Arr::only($params, self::REAUTH_FIELDS), fn ($value) => $value !== null);
        ksort($fields);

        return hash('sha256', serialize($fields));
    }

    /** Sends the browser to a page here and, after it, back to this request (as a GET, also when it was posted). */
    private function detour(string $to, Request $request, array $params): RedirectResponse
    {
        $request->session()->put('url.intended', $request->url().'?'.Arr::query($params));

        return redirect($to);
    }

    /** An OpenID Connect error for the app. */
    private function error(string $error, string $description, AuthorizationRequestInterface $authRequest): OAuthServerException
    {
        return $this->backToApp(new OAuthServerException($description, 0, $error), $authRequest);
    }

    /** The same error, sent to the app's validated redirect address with the state, as the library does. */
    private function backToApp(OAuthServerException $e, AuthorizationRequestInterface $authRequest): OAuthServerException
    {
        $redirect = new OAuthServerException(
            $e->getMessage(), $e->getCode(), $e->getErrorType(), $e->getHttpStatusCode(), $e->getHint(),
            $authRequest->getRedirectUri() ?? Arr::wrap($authRequest->getClient()->getRedirectUri())[0],
        );
        $redirect->setPayload(['state' => $authRequest->getState(), ...$redirect->getPayload()]);

        return $redirect;
    }

    /** Errors the library can send back to a validated app go there; anything else stays on this server. */
    private function refuse(OAuthServerException $e, ResponseInterface $psrResponse): Response
    {
        if ($e->hasRedirect()) {
            return $this->convertResponse($e->generateHttpResponse($psrResponse));
        }

        return response()->view('oauth-error', ['reason' => match ($e->getErrorType()) {
            'invalid_client' => 'The app is not registered, or the address it asked to return to is not one of its registered addresses.',
            'unauthorized_client' => 'This app is not allowed to sign users in.',
            default => 'The app sent an incomplete or malformed request.',
        }], 400);
    }
}
