<?php

namespace App\Sso\OAuth;

use App\Models\SignIn;
use Defuse\Crypto\Crypto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Throwable;

/** Finds the sign-in behind the code or refresh token presented at the token endpoint. */
class SignInResolver
{
    /** @return array{signIn: SignIn, refresh: bool}|null */
    public function fromTokenRequest(Request $request): ?array
    {
        $grant = $request->post('grant_type');

        try {
            $signInId = match ($grant) {
                'authorization_code' => $this->signInIdOf(Passport::authCode(), $this->payload($request->post('code'))['auth_code_id'] ?? null),
                // The old access token, already revoked by the refresh grant, still names the sign-in.
                'refresh_token' => $this->signInIdOf(Passport::token(), $this->payload($request->post('refresh_token'))['access_token_id'] ?? null),
                default => null,
            };
            $signIn = is_string($signInId) ? SignIn::find($signInId) : null;
        } catch (Throwable) {
            return null;
        }

        return $signIn ? ['signIn' => $signIn, 'refresh' => $grant === 'refresh_token'] : null;
    }

    /** Decrypts a code or refresh token the way the league server encrypted it. */
    private function payload(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $json = Crypto::decryptWithPassword(trim($value), Passport::tokenEncryptionKey(app('encrypter')));

        return (array) json_decode($json, true);
    }

    private function signInIdOf(Model $model, mixed $id): mixed
    {
        return is_string($id) ? $model->newQuery()->whereKey($id)->value('sign_in_id') : null;
    }
}
