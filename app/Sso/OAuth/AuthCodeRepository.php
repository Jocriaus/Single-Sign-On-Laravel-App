<?php

namespace App\Sso\OAuth;

use App\Sso\Logout\BackChannelLogout;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/** Records which sign-in each authorization code belongs to. */
class AuthCodeRepository extends PassportAuthCodeRepository
{
    public function __construct(private SignInContext $context, private BackChannelLogout $logout)
    {
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        parent::persistNewAuthCode($authCodeEntity);

        Passport::authCode()->newQuery()->whereKey($authCodeEntity->getIdentifier())
            ->update(['sign_in_id' => $this->context->signIn()?->getKey()]);
    }

    /**
     * A code presented again after its first use may have been stolen: the tokens it produced stop working
     * (RFC 6749 section 4.1.2). The sign-in itself stays, so the app can simply ask again. A code revoked by
     * signing out takes the same path; its tokens are revoked already.
     */
    public function isAuthCodeRevoked(string $codeId): bool
    {
        if (! parent::isAuthCodeRevoked($codeId)) {
            return false;
        }

        $signInId = Passport::authCode()->newQuery()->whereKey($codeId)->value('sign_in_id');
        if (is_string($signInId)) {
            $this->logout->revokeTokens([$signInId]);
        }

        return true;
    }
}
