<?php

namespace App\Sso\OAuth;

use App\Models\SignIn;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\CryptoException;
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
        [$value, $model, $idKey] = match ($grant) {
            'authorization_code' => [$request->post('code'), Passport::authCode(), 'auth_code_id'],
            // The old access token, already revoked by the refresh grant, still names the sign-in.
            'refresh_token' => [$request->post('refresh_token'), Passport::token(), 'access_token_id'],
            default => [null, null, null],
        };
        if ($model === null || ! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            // Decrypted the way the league server encrypted it.
            $payload = json_decode(Crypto::decryptWithPassword(trim($value), Passport::tokenEncryptionKey(app('encrypter'))), true);
            $id = is_array($payload) ? ($payload[$idKey] ?? null) : null;
            if (! is_string($id)) {
                return null;
            }
            $signInId = $model->newQuery()->whereKey($id)->value('sign_in_id');
            $signIn = is_string($signInId) ? SignIn::find($signInId) : null;
        } catch (CryptoException) {
            return null;
        } catch (Throwable $e) {
            // Not bad input (league already accepted this value): a fault worth logging.
            report($e);

            return null;
        }

        return $signIn ? ['signIn' => $signIn, 'refresh' => $grant === 'refresh_token'] : null;
    }
}
