<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SignIn;
use App\Sso\Accounts\UserClaims;
use App\Sso\Activity;
use App\Sso\Apps\AppAccess;
use App\Sso\OAuth\SignInContext;
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

/** Signs the SSO user in to an app and sends the browser back to it with an authorization code. */
class AuthorizeController extends Controller
{
    use ConvertsPsrResponses;

    public function __construct(
        private AuthorizationServer $server,
        private SignInContext $context,
    ) {
    }

    public function __invoke(Request $request, ServerRequestInterface $psrRequest, ResponseInterface $psrResponse): Response
    {
        $sid = $request->session()->get('sso.sid');
        $authTime = $request->session()->get('sso.auth_time');
        if (! is_string($sid) || ! is_int($authTime)) {
            // Signed in without going through the login page: start over there.
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->guest(route('login'));
        }

        try {
            $authRequest = $this->server->validateAuthorizationRequest($psrRequest);
            $this->requireOpenIdAndPkce($authRequest);

            $client = Client::find($authRequest->getClient()->getIdentifier());
            if ($client !== null && ! AppAccess::allows($client, $request->user())) {
                Activity::log($request->user(), "Refused sign-in to app {$client->name}: no allowed role", Activity::AUTH, 'OAuth2');

                return response()->view('no-access', [
                    'appName' => $client->name,
                    'displayName' => UserClaims::displayName($request->user()),
                ], 403)->header('Cache-Control', 'no-store');
            }

            $this->context->set(SignIn::create([
                'user_id' => $request->user()->getKey(),
                'client_id' => $authRequest->getClient()->getIdentifier(),
                'sid' => $sid,
                'nonce' => is_string($nonce = $request->query('nonce')) && $nonce !== '' ? $nonce : null,
                'auth_time' => now()->setTimestamp($authTime),
                'started_at' => now(),
            ]));
            $authRequest->setUser(new UserEntity((string) $request->user()->getKey()));
            $authRequest->setAuthorizationApproved(true);

            return $this->convertResponse($this->server->completeAuthorizationRequest($authRequest, $psrResponse));
        } catch (OAuthServerException $e) {
            return $this->refuse($e, $psrResponse);
        }
    }

    /** OpenID Connect only, with PKCE (S256) unless the app has it switched off. */
    private function requireOpenIdAndPkce(AuthorizationRequestInterface $authRequest): void
    {
        $scopes = array_map(fn (ScopeEntityInterface $scope) => $scope->getIdentifier(), $authRequest->getScopes());
        if (! in_array('openid', $scopes, true)) {
            throw $this->backToApp(OAuthServerException::invalidScope('openid'), $authRequest);
        }

        if ($authRequest->getCodeChallenge() === null) {
            if (Client::find($authRequest->getClient()->getIdentifier())?->require_pkce !== false) {
                throw $this->backToApp(OAuthServerException::invalidRequest('code_challenge', 'A code_challenge (S256) is required.'), $authRequest);
            }
        } elseif ($authRequest->getCodeChallengeMethod() !== 'S256') {
            throw $this->backToApp(OAuthServerException::invalidRequest('code_challenge_method', 'Only S256 is supported.'), $authRequest);
        }
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
