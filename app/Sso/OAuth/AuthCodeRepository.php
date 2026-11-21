<?php

namespace App\Sso\OAuth;

use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/** Records which sign-in each authorization code belongs to. */
class AuthCodeRepository extends PassportAuthCodeRepository
{
    public function __construct(private SignInContext $context)
    {
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        parent::persistNewAuthCode($authCodeEntity);

        Passport::authCode()->newQuery()->whereKey($authCodeEntity->getIdentifier())
            ->update(['sign_in_id' => $this->context->signIn()?->getKey()]);
    }
}
