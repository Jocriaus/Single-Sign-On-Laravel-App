<?php

namespace App\Sso\OAuth;

use App\Models\User;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

/**
 * Adds the OpenID Connect ID token to the token response. Created once at boot, so everything
 * it needs is resolved per call.
 */
class IdTokenResponse extends BearerTokenResponse
{
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $context = app(SignInContext::class);
        $signIn = $context->signIn();
        $scopes = array_map(fn (ScopeEntityInterface $scope) => $scope->getIdentifier(), $accessToken->getScopes());
        $user = $signIn ? User::find($accessToken->getUserIdentifier()) : null;

        if (! $user || ! in_array('openid', $scopes, true)) {
            return [];
        }

        return ['id_token' => app(IdTokenFactory::class)->make(
            $user, $accessToken->getClient()->getIdentifier(), $signIn, $scopes, ! $context->isRefresh()
        )];
    }
}
