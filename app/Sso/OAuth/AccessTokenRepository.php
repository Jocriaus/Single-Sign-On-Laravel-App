<?php

namespace App\Sso\OAuth;

use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passport\Bridge\AccessTokenRepository as PassportAccessTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/** Ties every access token from a code or refresh token to its sign-in. */
class AccessTokenRepository extends PassportAccessTokenRepository
{
    public function __construct(
        Dispatcher $events,
        private SignInResolver $resolver,
        private SignInContext $context,
    ) {
        parent::__construct($events);
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $request = request();
        $resolved = $this->resolver->fromTokenRequest($request);
        $this->context->set(null);

        if ($resolved === null && in_array($request->post('grant_type'), ['authorization_code', 'refresh_token'], true)) {
            throw OAuthServerException::invalidGrant('The sign-in behind this grant no longer exists.');
        }
        if ($resolved !== null && (
            $resolved['signIn']->client_id !== $accessTokenEntity->getClient()->getIdentifier()
            || (string) $resolved['signIn']->user_id !== (string) $accessTokenEntity->getUserIdentifier()
        )) {
            throw OAuthServerException::invalidGrant('The sign-in behind this grant belongs to another app or user.');
        }
        $this->context->set($resolved['signIn'] ?? null, $resolved['refresh'] ?? false);

        if ($resolved !== null) {
            // Decrypting the grant above takes ~0.1 s after the grant stamped the expiry; restart the
            // lifetime (Passport gives both grants tokensExpireIn) so expires_in and exp are exact.
            $accessTokenEntity->setExpiryDateTime((new DateTimeImmutable)->add(Passport::tokensExpireIn()));
        }

        parent::persistNewAccessToken($accessTokenEntity);

        if ($resolved !== null) {
            Passport::token()->newQuery()->whereKey($accessTokenEntity->getIdentifier())
                ->update(['sign_in_id' => $resolved['signIn']->getKey()]);
        }
    }
}
