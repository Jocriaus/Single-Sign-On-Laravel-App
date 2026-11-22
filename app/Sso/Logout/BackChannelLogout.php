<?php

namespace App\Sso\Logout;

use App\Models\Client;
use App\Models\SignIn;
use App\Models\User;
use App\Sso\OAuth\SigningKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use stdClass;
use Throwable;

/**
 * Ends app sign-ins: their codes and tokens stop working, and every app with a back-channel logout address
 * is told (OpenID Connect Back-Channel Logout 1.0).
 */
class BackChannelLogout
{
    public const EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public function __construct(private SigningKey $key)
    {
    }

    /** The user's sign-ins made from one SSO browser session. */
    public function signOutSession(User $user, string $sid): void
    {
        $this->signOut($user, SignIn::where('user_id', $user->getKey())->where('sid', $sid)->get());
    }

    /** Every sign-in of the user, from every browser. */
    public function signOutEverywhere(User $user): void
    {
        $this->signOut($user, SignIn::where('user_id', $user->getKey())->get());
    }

    /** @param  Collection<int, SignIn>  $signIns */
    private function signOut(User $user, Collection $signIns): void
    {
        if ($signIns->isEmpty()) {
            return;
        }

        // Built while the sign-ins still exist; sent after the response so a slow app can't hold up the browser.
        $notices = $this->notices($user, $signIns);

        $ids = $signIns->modelKeys();
        DB::transaction(function () use ($ids) {
            // Revoked, not deleted: a refresh resolves its sign-in through the old access token's row.
            $tokens = Passport::token()->newQuery()->whereIn('sign_in_id', $ids);
            Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', (clone $tokens)->select('id'))
                ->update(['revoked' => true]);
            $tokens->update(['revoked' => true]);
            Passport::authCode()->newQuery()->whereIn('sign_in_id', $ids)->update(['revoked' => true]);
            SignIn::whereKey($ids)->delete();
        });

        if ($notices !== []) {
            dispatch(static function () use ($notices) {
                foreach ($notices as [$clientId, $uri, $token]) {
                    self::send($clientId, $uri, $token);
                }
            })->afterResponse();
        }
    }

    /**
     * One logout token per app and SSO session, for the apps with a back-channel logout address.
     *
     * @param  Collection<int, SignIn>  $signIns
     * @return list<array{0: string, 1: string, 2: string}> client id, address, logout token
     */
    private function notices(User $user, Collection $signIns): array
    {
        $addresses = Client::whereKey($signIns->pluck('client_id')->unique()->values()->all())
            ->whereNotNull('backchannel_logout_uri')
            ->pluck('backchannel_logout_uri', 'id');

        return $signIns
            ->unique(fn (SignIn $signIn) => $signIn->client_id."\n".$signIn->sid)
            ->filter(fn (SignIn $signIn) => filled($addresses[$signIn->client_id] ?? null))
            ->map(fn (SignIn $signIn) => [
                $signIn->client_id,
                $addresses[$signIn->client_id],
                $this->logoutToken($user, $signIn->client_id, $signIn->sid),
            ])
            ->values()
            ->all();
    }

    private function logoutToken(User $user, string $clientId, string $sid): string
    {
        $now = time();

        return $this->key->sign([
            'iss' => config('sso.issuer'),
            'sub' => (string) $user->getKey(),
            'aud' => $clientId,
            'iat' => $now,
            'exp' => $now + config('sso.logout_token_minutes') * 60,
            'jti' => (string) Str::uuid(),
            'sid' => $sid,
            // The spec requires an empty JSON object; an empty PHP array would encode as [].
            'events' => [self::EVENT => new stdClass],
        ], ['typ' => 'logout+jwt']);
    }

    /** A failing app is logged and otherwise ignored: the user is signed out here either way. */
    private static function send(string $clientId, string $uri, string $token): void
    {
        try {
            $status = Http::asForm()->connectTimeout(3)->timeout(5)->post($uri, ['logout_token' => $token])->status();
            if ($status === 200 || $status === 204) {
                return;
            }
            $problem = "answered HTTP {$status}";
        } catch (Throwable $e) {
            $problem = str_replace($token, '[logout token]', get_class($e).': '.$e->getMessage());
        }

        Log::warning("Back-channel logout to app {$clientId} at {$uri} failed: {$problem}");
    }
}
