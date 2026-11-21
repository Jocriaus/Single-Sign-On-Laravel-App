<?php

namespace App\Sso\OAuth;

use App\Models\SignIn;
use App\Models\User;
use App\Sso\Accounts\UserClaims;

class IdTokenFactory
{
    public function __construct(private SigningKey $key)
    {
    }

    /** @param list<string> $scopes the scopes granted to the access token */
    public function make(User $user, string $clientId, SignIn $signIn, array $scopes, bool $withNonce): string
    {
        $now = time();
        $claims = [
            'iss' => config('sso.issuer'),
            'sub' => (string) $user->getKey(),
            'aud' => $clientId,
            'iat' => $now,
            'exp' => $now + config('sso.id_token_minutes') * 60,
            'auth_time' => $signIn->auth_time->getTimestamp(),
            'sid' => $signIn->sid,
            'roles' => UserClaims::roles($user),
            'user_type' => UserClaims::userType($user),
        ];

        if ($withNonce && filled($signIn->nonce)) {
            $claims['nonce'] = $signIn->nonce;
        }
        if (in_array('profile', $scopes, true)) {
            $claims['preferred_username'] = $user->username;
            $claims['name'] = UserClaims::displayName($user);
        }
        if (in_array('email', $scopes, true)) {
            $claims['email'] = $user->email;
        }

        return $this->key->sign($claims);
    }
}
