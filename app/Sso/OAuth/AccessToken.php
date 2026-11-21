<?php

namespace App\Sso\OAuth;

use App\Models\User;
use App\Sso\Accounts\UserClaims;
use App\Sso\Activity;
use DateTimeImmutable;
use Laravel\Passport\Bridge\AccessToken as PassportAccessToken;
use Lcobucci\JWT\Token;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;

/** Passport's access token entity, with this server's issuer, kid header and user claims. */
class AccessToken extends PassportAccessToken
{
    // Imported again so toString() calls this class's convertToJWT() and it can read the key it was given;
    // the parent's copies are private.
    use AccessTokenTrait;

    private function convertToJWT(): Token
    {
        $this->initJwtConfiguration();

        $scopes = array_map(fn (ScopeEntityInterface $scope) => $scope->getIdentifier(), $this->getScopes());
        $user = $this->getUserIdentifier() !== null ? User::find($this->getUserIdentifier()) : null;
        $now = new DateTimeImmutable('@'.time());

        $builder = $this->jwtConfiguration->builder()
            ->withHeader('kid', app(SigningKey::class)->kid())
            ->issuedBy(config('sso.issuer'))
            ->relatedTo($this->getSubjectIdentifier())
            ->permittedFor($this->getClient()->getIdentifier())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt(new DateTimeImmutable('@'.$this->getExpiryDateTime()->getTimestamp()))
            ->withClaim('scopes', $scopes)
            ->withClaim('scope', implode(' ', $scopes));

        if ($user) {
            $builder = $builder
                ->withClaim('roles', UserClaims::roles($user))
                ->withClaim('user_type', UserClaims::userType($user))
                ->withClaim('preferred_username', $user->username);
        }

        Activity::log($user, "Access token issued to app {$this->getClient()->getName()}", Activity::CREATE, 'OAuth2');

        return $builder->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey());
    }
}
