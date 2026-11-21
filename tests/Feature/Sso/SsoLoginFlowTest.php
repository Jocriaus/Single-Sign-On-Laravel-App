<?php

namespace Tests\Feature\Sso;

use App\Models\ActivityLog;
use App\Models\SignIn;
use App\Models\User;
use App\Sso\Accounts\AutomaticRoles;
use App\Sso\Accounts\UserClaims;
use App\Sso\OAuth\SigningKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class SsoLoginFlowTest extends TestCase
{
    use OAuthFlow;

    /** @return array{0: array{client: \App\Models\Client, secret: string}, 1: string, 2: string} app, code, verifier */
    private function codeForNewApp(array $query = []): array
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());
        $verifier = Str::random(64);
        $code = $this->codeFrom($this->authorize($app['client'], $query + ['code_challenge' => $this->challenge($verifier)]));

        return [$app, $code, $verifier];
    }

    private function refresh(array $app, string $refreshToken)
    {
        return $this->post('/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $app['client']->id,
            'client_secret' => $app['secret'],
        ]);
    }

    public function test_full_flow_browser_to_tokens(): void
    {
        $this->seed();
        $app = $this->registerApp(['name' => 'Student Portal', 'redirect_uris' => 'https://portal.test/cb']);
        $user = User::factory()->student()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        app(AutomaticRoles::class)->sync($user);
        $verifier = Str::random(64);
        $query = ['nonce' => 'n-1', 'state' => 's-1', 'code_challenge' => $this->challenge($verifier)];

        $this->authorize($app['client'], $query)->assertRedirect('/login');            // no SSO session yet
        $this->get('/login')->assertSee('Student Portal');
        $this->post('/login', ['username' => $user->username, 'password' => 'correct-horse-battery'])
             ->assertRedirectContains('/oauth2/authorize');
        $back = $this->authorize($app['client'], $query);
        $back->assertRedirectContains('https://portal.test/cb?');
        parse_str(parse_url($back->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('s-1', $q['state']);

        $tokens = $this->exchange($app['client'], $app['secret'], $q['code'], $verifier)->assertOk()->json();
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(900, $tokens['expires_in']);
        $this->assertNotEmpty($tokens['refresh_token']);

        $id = $this->claims($tokens['id_token']);
        $this->assertSame(config('sso.issuer'), $id['iss']);
        $this->assertSame((string) $user->id, $id['sub']);
        $this->assertSame($app['client']->id, $id['aud']);
        $this->assertSame('n-1', $id['nonce']);
        $this->assertSame(session('sso.sid'), $id['sid']);
        $this->assertSame(900, $id['exp'] - $id['iat']);
        $this->assertSame($user->username, $id['preferred_username']);
        $this->assertSame('Juan Dela Cruz', $id['name']);
        $this->assertSame($user->email, $id['email']);
        $this->assertSame(['STUDENT'], $id['roles']);
        $this->assertSame('Student', $id['user_type']);

        $access = $this->claims($tokens['access_token']);
        $this->assertSame((string) $user->id, $access['sub']);
        $this->assertSame('openid profile email', $access['scope']);
        $this->assertSame($user->username, $access['preferred_username']);
        $this->assertArrayNotHasKey('email', $access);

        // Both tokens carry a valid signature from the published key.
        $key = app(SigningKey::class);
        $this->assertSame($id, $key->decode($tokens['id_token']));
        $this->assertSame($access, $key->decode($tokens['access_token']));
        $this->assertSame(session('sso.auth_time'), $id['auth_time']);
        $this->assertSame(config('sso.issuer'), $access['iss']);
        $this->assertSame($app['client']->id, $access['aud']);
        $this->assertSame(['openid', 'profile', 'email'], $access['scopes']);
        $this->assertSame(['STUDENT'], $access['roles']);
        $this->assertSame('Student', $access['user_type']);
        $this->assertEqualsWithDelta(900, $access['exp'] - $access['iat'], 1);
    }

    public function test_second_app_needs_no_password(): void
    {
        $user = User::factory()->create();
        $first = $this->registerApp(['name' => 'Library', 'redirect_uris' => 'https://library.test/cb']);
        $second = $this->registerApp(['name' => 'Grades', 'redirect_uris' => 'https://grades.test/cb']);
        $verifier = Str::random(64);
        $this->post('/login', ['username' => $user->username, 'password' => 'correct-horse-battery']);

        $this->codeFrom($this->authorize($first['client'], ['code_challenge' => $this->challenge($verifier)]));
        $response = $this->authorize($second['client'], ['code_challenge' => $this->challenge($verifier)]);

        $response->assertRedirectContains('https://grades.test/cb?');
        $this->exchange($second['client'], $second['secret'], $this->codeFrom($response), $verifier)->assertOk();
        $this->assertSame([session('sso.sid')], SignIn::pluck('sid')->unique()->values()->all());
        $this->assertSame(2, SignIn::count());
    }

    public function test_claims_follow_the_scopes_granted(): void
    {
        $tokens = $this->tokensFor(User::factory()->create(), null, 'openid');
        $id = $this->claims($tokens['id_token']);

        foreach (['name', 'preferred_username', 'email'] as $claim) {
            $this->assertArrayNotHasKey($claim, $id);
        }
        $this->assertArrayHasKey('roles', $id);
        $this->assertSame('openid', $this->claims($tokens['access_token'])['scope']);
    }

    public function test_name_falls_back_to_the_username(): void
    {
        $this->seed();
        $admin = User::findByUsername('admin');
        $admin->update(['must_change_password' => false]);

        $this->assertSame('admin', $this->claims($this->tokensFor($admin)['id_token'])['name']);
    }

    public function test_unregistered_redirect_address_gets_an_error_page_not_a_redirect(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());

        $response = $this->authorize($app['client'], [
            'redirect_uri' => 'https://evil.test/cb',
            'state' => 's-1',
            'code_challenge' => $this->challenge(Str::random(64)),
        ]);

        $response->assertStatus(400)->assertViewIs('oauth-error')
            ->assertSee("This sign-in request isn't valid.", false)
            ->assertDontSee('evil.test');
        $this->assertFalse($response->headers->has('Location'));
        $this->assertSame(0, SignIn::count());
    }

    public function test_unknown_app_gets_an_error_page(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());

        $this->authorize($app['client'], ['client_id' => 'no-such-app', 'code_challenge' => $this->challenge(Str::random(64))])
            ->assertStatus(400)->assertViewIs('oauth-error');
    }

    public function test_scope_the_app_is_not_allowed_is_dropped(): void
    {
        $app = $this->registerApp(['scopes' => 'openid']);
        $tokens = $this->tokensFor(User::factory()->create(), $app, 'openid sso.admin');

        $access = $this->claims($tokens['access_token']);
        $this->assertSame('openid', $access['scope']);
        $this->assertSame(['openid'], $access['scopes']);
    }

    public function test_request_without_openid_scope_is_refused(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());

        $query = $this->redirectQuery($this->authorize($app['client'], [
            'scope' => 'profile email',
            'state' => 's-9',
            'code_challenge' => $this->challenge(Str::random(64)),
        ])->assertRedirectContains('https://app.test/cb?'));

        $this->assertSame('invalid_scope', $query['error']);
        $this->assertSame('s-9', $query['state']);
        $this->assertArrayNotHasKey('code', $query);
        $this->assertSame(0, SignIn::count());
    }

    public function test_request_without_pkce_is_refused(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());

        $query = $this->redirectQuery($this->authorize($app['client'], ['state' => 's-2'])
            ->assertRedirectContains('https://app.test/cb?'));

        $this->assertSame('invalid_request', $query['error']);
        $this->assertSame('s-2', $query['state']);
        $this->assertArrayNotHasKey('code', $query);
        $this->assertSame(0, SignIn::count());
    }

    public function test_plain_pkce_method_is_refused(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());
        $verifier = Str::random(64);

        $query = $this->redirectQuery($this->authorize($app['client'], [
            'code_challenge' => $verifier,
            'code_challenge_method' => 'plain',
        ])->assertRedirectContains('https://app.test/cb?'));

        $this->assertSame('invalid_request', $query['error']);
        $this->assertArrayNotHasKey('code', $query);
    }

    public function test_user_who_must_change_password_cannot_reach_an_app(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->mustChangePassword()->create());

        $this->authorize($app['client'], ['code_challenge' => $this->challenge(Str::random(64))])
            ->assertRedirect('/change-password');
        $this->assertSame(0, SignIn::count());
    }

    public function test_session_without_the_sso_sign_in_details_starts_over_at_the_login_page(): void
    {
        $app = $this->registerApp();
        $this->actingAs(User::factory()->create());

        $this->authorize($app['client'], ['code_challenge' => $this->challenge(Str::random(64))])
            ->assertRedirect('/login');
        $this->assertGuest();
        $this->assertStringContainsString('/oauth2/authorize', session('url.intended'));
        $this->assertSame(0, SignIn::count());
    }

    public function test_wrong_client_secret_is_refused(): void
    {
        [$app, $code, $verifier] = $this->codeForNewApp();

        $this->exchange($app['client'], 'wrong-secret', $code, $verifier)
            ->assertStatus(401)->assertJson(['error' => 'invalid_client']);
    }

    public function test_wrong_code_verifier_is_refused(): void
    {
        [$app, $code] = $this->codeForNewApp();

        $this->exchange($app['client'], $app['secret'], $code, Str::random(64))
            ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_client_secret_basic_and_post_both_work(): void
    {
        foreach (['basic', 'post'] as $method) {
            [$app, $code, $verifier] = $this->codeForNewApp();

            $response = $this->exchange($app['client'], $app['secret'], $code, $verifier, $method)
                ->assertOk()->assertJsonStructure(['access_token', 'id_token', 'refresh_token']);
            $this->assertSame([], $response->headers->getCookies(), $method);
        }
    }

    public function test_every_token_has_the_kid_of_the_published_key(): void
    {
        $tokens = $this->tokensFor(User::factory()->create());
        $kid = app(SigningKey::class)->kid();

        foreach (['access_token', 'id_token'] as $token) {
            $this->assertSame($kid, $this->header($tokens[$token])['kid'], $token);
            $this->assertSame('RS256', $this->header($tokens[$token])['alg'], $token);
        }
    }

    public function test_the_sign_in_is_recorded_on_the_code_and_the_token(): void
    {
        $user = User::factory()->create();
        $this->signInToSso($user);
        $app = $this->registerApp();
        $verifier = Str::random(64);
        $code = $this->codeFrom($this->authorize($app['client'], ['nonce' => 'n-7', 'code_challenge' => $this->challenge($verifier)]));

        $signIn = SignIn::sole();
        $this->assertSame($user->id, $signIn->user_id);
        $this->assertSame($app['client']->id, $signIn->client_id);
        $this->assertSame(session('sso.sid'), $signIn->sid);
        $this->assertSame('n-7', $signIn->nonce);
        $this->assertSame(session('sso.auth_time'), $signIn->auth_time->getTimestamp());
        $this->assertNotNull($signIn->started_at);
        $this->assertTrue($signIn->user->is($user));
        $this->assertTrue($signIn->client->is($app['client']));
        $this->assertSame($signIn->id, DB::table('oauth_auth_codes')->value('sign_in_id'));

        $this->exchange($app['client'], $app['secret'], $code, $verifier)->assertOk();
        $this->assertSame($signIn->id, DB::table('oauth_access_tokens')->value('sign_in_id'));
        $this->assertSame(1, ActivityLog::where([
            'activity' => 'Access token issued to app Test App',
            'activity_type' => 'CREATE',
            'module' => 'OAuth2',
            'user_id' => $user->id,
        ])->count());
    }

    public function test_code_without_a_sign_in_is_refused(): void
    {
        [$app, $code, $verifier] = $this->codeForNewApp();
        DB::table('oauth_auth_codes')->update(['sign_in_id' => null]);

        $this->exchange($app['client'], $app['secret'], $code, $verifier)
            ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_refresh_keeps_the_sign_in_and_leaves_out_the_nonce(): void
    {
        [$app, $code, $verifier] = $this->codeForNewApp(['nonce' => 'n-3']);
        $first = $this->exchange($app['client'], $app['secret'], $code, $verifier)->assertOk()->json();

        $second = $this->refresh($app, $first['refresh_token'])->assertOk()->json();

        $before = $this->claims($first['id_token']);
        $after = $this->claims($second['id_token']);
        $this->assertSame('n-3', $before['nonce']);
        $this->assertArrayNotHasKey('nonce', $after);
        $this->assertSame($before['sid'], $after['sid']);
        $this->assertSame($before['auth_time'], $after['auth_time']);
        $this->assertSame([SignIn::sole()->id], DB::table('oauth_access_tokens')->distinct()->pluck('sign_in_id')->all());
        $this->refresh($app, $first['refresh_token'])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_refresh_without_a_sign_in_is_refused(): void
    {
        [$app, $code, $verifier] = $this->codeForNewApp();
        $tokens = $this->exchange($app['client'], $app['secret'], $code, $verifier)->assertOk()->json();
        DB::table('oauth_access_tokens')->update(['sign_in_id' => null]);

        $this->refresh($app, $tokens['refresh_token'])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_user_claims(): void
    {
        $this->seed();
        $user = User::factory()->student()->create([
            'first_name' => ' Ana ', 'last_name' => 'Reyes', 'middle_name' => 'Santos', 'middle_initial' => 'S.',
        ]);
        app(AutomaticRoles::class)->sync($user);

        $this->assertSame('Ana Reyes', UserClaims::displayName($user));
        $this->assertSame(['STUDENT'], UserClaims::roles($user));
        $this->assertSame('Student', UserClaims::userType($user));
        $this->assertSame([
            'first_name' => ' Ana ', 'last_name' => 'Reyes', 'middle_name' => 'Santos', 'middle_initial' => 'S.',
            'studentNo' => $user->student_no,
        ], UserClaims::profile($user));
        $this->assertNull(UserClaims::inactiveReason($user));

        $user->forceFill(['must_change_password' => true])->save();
        $this->assertSame('The user must change their password before signing in to apps.', UserClaims::inactiveReason($user));
        $user->forceFill(['account_locked' => true, 'locked_until' => now()->addMinutes(5)])->save();
        $this->assertSame('This account is locked.', UserClaims::inactiveReason($user));
        $user->forceFill(['status_flag' => false])->save();
        $this->assertSame('This account is disabled.', UserClaims::inactiveReason($user));
    }
}
