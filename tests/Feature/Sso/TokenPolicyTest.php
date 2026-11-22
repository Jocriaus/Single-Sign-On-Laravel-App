<?php

namespace Tests\Feature\Sso;

use App\Models\Role;
use App\Models\SignIn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class TokenPolicyTest extends TestCase
{
    use OAuthFlow;

    private function assertRefused($response, ?string $hint = null): void
    {
        $response->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        if ($hint !== null) {
            $this->assertSame($hint, $response->json('hint'));
        }
    }

    public function test_refresh_returns_new_tokens_including_an_id_token_with_the_same_sid(): void
    {
        $first = $this->tokensFor(User::factory()->create(), null, 'openid profile email');

        $second = $this->refresh($first, $first['refresh_token'])->assertOk()->json();

        foreach (['access_token', 'refresh_token', 'id_token'] as $key) {
            $this->assertNotEmpty($second[$key]);
        }
        // An ID token carries no jti, so one issued in the same second with the same claims can repeat.
        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $before = $this->claims($first['id_token']);
        $after = $this->claims($second['id_token']);
        $this->assertSame($before['sid'], $after['sid']);
        $this->assertSame($before['auth_time'], $after['auth_time']);
        $this->assertArrayNotHasKey('nonce', $after);
    }

    public function test_a_refresh_token_works_only_once(): void
    {
        $first = $this->tokensFor(User::factory()->create());
        $this->refresh($first, $first['refresh_token'])->assertOk();

        $this->assertRefused($this->refresh($first, $first['refresh_token']));
    }

    public function test_an_authorization_code_works_only_once(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());
        $verifier = Str::random(64);
        $code = $this->codeFrom($this->authorize($app['client'], ['code_challenge' => $this->challenge($verifier)]));

        $this->exchange($app['client'], $app['secret'], $code, $verifier)->assertOk();

        $this->assertRefused($this->exchange($app['client'], $app['secret'], $code, $verifier));
    }

    public function test_refresh_stops_eight_hours_after_the_sign_in_started(): void
    {
        $start = now()->startOfSecond();
        $this->travelTo($start);
        $first = $this->tokensFor(User::factory()->create());

        $this->travelTo($start->copy()->addHours(4));
        $second = $this->refresh($first, $first['refresh_token'])->assertOk()->json();

        $this->travelTo($start->copy()->addHours(8)->addMinute());
        $this->assertRefused(
            $this->refresh($first, $second['refresh_token']),
            'The sign-in has expired. Sign in again.',
        );
    }

    public function test_refresh_picks_up_role_changes(): void
    {
        $user = User::factory()->create();
        $first = $this->tokensFor($user);
        $this->assertNotContains('ADMIN', $this->claims($first['access_token'])['roles']);
        $user->roles()->attach(Role::firstOrCreate(['role_name' => 'ADMIN'])->id);

        $second = $this->refresh($first, $first['refresh_token'])->assertOk()->json();

        $this->assertContains('ADMIN', $this->claims($second['access_token'])['roles']);
    }

    public function test_disabled_user_cannot_refresh(): void
    {
        $user = User::factory()->create();
        $first = $this->tokensFor($user);
        $user->update(['status_flag' => false]);

        $this->assertRefused($this->refresh($first, $first['refresh_token']), 'This account is disabled.');
    }

    public function test_locked_user_cannot_refresh(): void
    {
        $user = User::factory()->create();
        $first = $this->tokensFor($user);
        $user->forceFill(['account_locked' => true, 'locked_until' => now()->addMinutes(15)])->save();

        $this->assertRefused($this->refresh($first, $first['refresh_token']), 'This account is locked.');
    }

    public function test_user_who_must_change_password_cannot_refresh(): void
    {
        $user = User::factory()->create();
        $first = $this->tokensFor($user);
        $user->forceFill(['must_change_password' => true])->save();

        $this->assertRefused(
            $this->refresh($first, $first['refresh_token']),
            'The user must change their password before signing in to apps.',
        );
    }

    public function test_deleted_user_cannot_refresh(): void
    {
        $user = User::factory()->create();
        $first = $this->tokensFor($user);
        $user->delete();

        $this->assertRefused($this->refresh($first, $first['refresh_token']), 'This account no longer exists.');
        $this->assertSame(1, DB::table('oauth_access_tokens')->count());
    }

    public function test_refresh_token_expires_after_eight_hours(): void
    {
        $now = now()->startOfSecond();
        $this->travelTo($now);
        $this->tokensFor(User::factory()->create());

        $expires = DB::table('oauth_refresh_tokens')->value('expires_at');
        $this->assertEqualsWithDelta($now->copy()->addHours(8)->timestamp, \Carbon\Carbon::parse($expires)->timestamp, 3);
    }
}
