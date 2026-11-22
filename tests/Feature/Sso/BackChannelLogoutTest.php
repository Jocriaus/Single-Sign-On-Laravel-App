<?php

namespace Tests\Feature\Sso;

use App\Models\SignIn;
use App\Models\User;
use App\Sso\OAuth\SigningKey;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class BackChannelLogoutTest extends TestCase
{
    use OAuthFlow;

    private const EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    protected function setUp(): void
    {
        parent::setUp();
        // Each test fakes the apps' answers; a first catch-all fake would shadow a later, specific one.
        Http::preventStrayRequests();
    }

    private function appAt(string $host, array $data = []): array
    {
        return $this->registerApp($data + [
            'name' => ucfirst($host),
            'redirect_uris' => "https://{$host}.test/cb",
            'backchannel_logout_uri' => "https://{$host}.test/backchannel-logout",
            'post_logout_redirect_uris' => "https://{$host}.test/signed-out",
        ]);
    }

    /** Signs in to the app from the current SSO browser session: the token response plus 'client' and 'secret'. */
    private function signInToApp(array $app): array
    {
        $verifier = Str::random(64);
        $code = $this->codeFrom($this->authorize($app['client'], [
            'state' => 's',
            'nonce' => 'n-'.Str::random(8),
            'code_challenge' => $this->challenge($verifier),
        ]));

        return $this->exchange($app['client'], $app['secret'], $code, $verifier)->assertOk()->json() + $app;
    }

    /** The logout tokens sent so far, keyed by the address they went to. */
    private function logoutTokens(): array
    {
        $tokens = [];
        foreach (Http::recorded() as [$request]) {
            $tokens[$request->url()][] = $request['logout_token'];
        }

        return $tokens;
    }

    private function rawPayload(string $jwt): string
    {
        return base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/'));
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_signing_out_through_an_app_notifies_the_other_apps_of_that_session(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $library = $this->appAt('library');
        $grades = $this->appAt('grades');
        $this->signInToSso($user);
        $sid = session('sso.sid');
        $first = $this->signInToApp($library);
        $this->signInToApp($grades);

        $this->get('/connect/logout?'.http_build_query(['id_token_hint' => $first['id_token']]))
            ->assertRedirect('/login?logout');

        $tokens = $this->logoutTokens();
        $this->assertSame(['https://grades.test/backchannel-logout', 'https://library.test/backchannel-logout'], collect(array_keys($tokens))->sort()->values()->all());
        Http::assertSentCount(2);
        foreach (['library' => $library, 'grades' => $grades] as $host => $app) {
            $this->assertCount(1, $tokens["https://{$host}.test/backchannel-logout"]);
            $jwt = $tokens["https://{$host}.test/backchannel-logout"][0];
            $header = $this->header($jwt);
            $claims = $this->claims($jwt);

            $this->assertSame('logout+jwt', $header['typ']);
            $this->assertSame('RS256', $header['alg']);
            $this->assertNotEmpty($header['kid']);
            $this->assertSame(config('sso.issuer'), $claims['iss']);
            $this->assertSame($app['client']->id, $claims['aud']);
            $this->assertSame((string) $user->id, $claims['sub']);
            $this->assertSame($sid, $claims['sid']);
            $this->assertArrayHasKey(self::EVENT, $claims['events']);
            $this->assertStringContainsString('"events":{"'.self::EVENT.'":{}}', str_replace('\/', '/', $this->rawPayload($jwt)));
            $this->assertTrue(Str::isUuid($claims['jti']));
            $this->assertSame(120, $claims['exp'] - $claims['iat']);
            $this->assertArrayNotHasKey('nonce', $claims);
        }
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && $request->isForm()
            && array_keys($request->data()) === ['logout_token']);
        $this->assertSame(0, SignIn::count());
        $this->assertGuest();
    }

    public function test_the_logout_token_passes_the_starter_kits_checks(): void
    {
        Http::fake();
        $app = $this->appAt('library');
        $this->signInToSso(User::factory()->create());
        $tokens = $this->signInToApp($app);

        $this->get('/connect/logout?'.http_build_query(['id_token_hint' => $tokens['id_token']]));

        $jwt = $this->logoutTokens()['https://library.test/backchannel-logout'][0];
        $issuer = $this->get('/.well-known/openid-configuration')->json('issuer');
        $keys = JWK::parseKeySet($this->get('/oauth2/jwks')->json());
        $payload = JWT::decode($jwt, $keys);   // signature, kid and alg against the published keys

        $this->assertSame('logout+jwt', $this->header($jwt)['typ']);
        $this->assertSame($issuer, $payload->iss);
        $this->assertSame($app['client']->id, $payload->aud);
        foreach (['iat', 'jti', 'sid', 'events'] as $claim) {
            $this->assertObjectHasProperty($claim, $payload);
        }
        $this->assertLessThanOrEqual(5 * 60, time() - $payload->iat);
        $this->assertInstanceOf(stdClass::class, $payload->events->{self::EVENT});
        $this->assertObjectNotHasProperty('nonce', $payload);
    }

    public function test_signing_out_on_the_sso_home_page_does_the_same(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $library = $this->appAt('library');
        $grades = $this->appAt('grades');
        $this->signInToSso($user);
        $sid = session('sso.sid');
        $this->signInToApp($library);
        $this->signInToApp($grades);

        $this->post('/logout')->assertRedirect('/login?logout');

        Http::assertSentCount(2);
        foreach (['library' => $library, 'grades' => $grades] as $host => $app) {
            $claims = $this->claims($this->logoutTokens()["https://{$host}.test/backchannel-logout"][0]);
            $this->assertSame($app['client']->id, $claims['aud']);
            $this->assertSame($sid, $claims['sid']);
            $this->assertSame((string) $user->id, $claims['sub']);
        }
        $this->assertSame(0, SignIn::count());
        $this->assertGuest();
    }

    public function test_refresh_tokens_of_that_session_stop_working(): void
    {
        Http::fake();
        $app = $this->appAt('library');
        $this->signInToSso(User::factory()->create());
        $tokens = $this->signInToApp($app);
        $rotated = $this->refresh($app, $tokens['refresh_token'])->assertOk()->json();

        $this->post('/logout');

        $this->refresh($app, $rotated['refresh_token'])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        $this->getJson('/userinfo', $this->bearer($rotated['access_token']))->assertStatus(401);
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('revoked', false)->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->where('revoked', false)->count());
    }

    public function test_sign_ins_from_another_browser_session_are_left_alone(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $library = $this->appAt('library');
        $grades = $this->appAt('grades');
        $this->signInToSso($user);                  // the first browser
        $other = $this->signInToApp($library);
        $this->signInToSso($user);                  // a second browser: a new SSO session
        $this->signInToApp($grades);

        $this->post('/logout');

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://grades.test/backchannel-logout');
        Http::assertNotSent(fn (HttpRequest $request) => $request->url() === 'https://library.test/backchannel-logout');
        $this->refresh($library, $other['refresh_token'])->assertOk();
        $this->assertSame([$library['client']->id], SignIn::pluck('client_id')->all());
    }

    public function test_apps_without_a_logout_address_are_not_called_but_lose_their_tokens(): void
    {
        Http::fake();
        $app = $this->appAt('library', ['backchannel_logout_uri' => null]);
        $this->signInToSso(User::factory()->create());
        $tokens = $this->signInToApp($app);

        $this->post('/logout')->assertRedirect('/login?logout');

        Http::assertNothingSent();
        $this->refresh($app, $tokens['refresh_token'])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        $this->getJson('/userinfo', $this->bearer($tokens['access_token']))->assertStatus(401);
    }

    public function test_unused_codes_of_that_session_are_revoked(): void
    {
        Http::fake();
        $app = $this->appAt('library');
        $this->signInToSso(User::factory()->create());
        $verifier = Str::random(64);
        $code = $this->codeFrom($this->authorize($app['client'], ['code_challenge' => $this->challenge($verifier)]));

        $this->post('/logout');

        $this->assertSame(0, DB::table('oauth_auth_codes')->where('revoked', false)->count());
        $this->exchange($app['client'], $app['secret'], $code, $verifier)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_a_failing_app_does_not_stop_sign_out(): void
    {
        Http::fake([
            'https://library.test/*' => Http::response('', 500),
            'https://grades.test/*' => Http::failedConnection('Connection refused'),
        ]);
        Log::spy();
        $library = $this->appAt('library');
        $grades = $this->appAt('grades');
        $this->signInToSso(User::factory()->create());
        $tokens = $this->signInToApp($library);
        $this->signInToApp($grades);

        $this->get('/connect/logout?'.http_build_query([
            'id_token_hint' => $tokens['id_token'],
            'post_logout_redirect_uri' => 'https://library.test/signed-out',
        ]))->assertRedirect('https://library.test/signed-out');

        $sent = $this->logoutTokens();
        Http::assertSentCount(2);
        foreach (['library' => $library, 'grades' => $grades] as $host => $app) {
            $token = $sent["https://{$host}.test/backchannel-logout"][0];
            Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, $app['client']->id)
                && str_contains($message, "https://{$host}.test/backchannel-logout")
                && ! str_contains($message, $token))->once();
        }
        $this->assertSame(0, SignIn::count());
        $this->assertGuest();
    }

    public function test_after_sign_out_the_next_authorize_asks_for_the_password_again(): void
    {
        Http::fake();
        $app = $this->appAt('library');
        $this->signInToSso(User::factory()->create());
        $tokens = $this->signInToApp($app);

        $this->get('/connect/logout?'.http_build_query(['id_token_hint' => $tokens['id_token']]));

        $this->authorize($app['client'], ['code_challenge' => $this->challenge(Str::random(64))])->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_registered_post_logout_address_is_followed_with_state(): void
    {
        Http::fake();
        $app = $this->appAt('library', ['post_logout_redirect_uris' => 'https://library.test/signed-out https://library.test/bye?from=sso']);
        $user = User::factory()->create();
        $this->signInToSso($user);
        $tokens = $this->signInToApp($app);
        // An ID token that expired long ago still names the app.
        $expired = app(SigningKey::class)->sign([
            'iss' => config('sso.issuer'),
            'sub' => (string) $user->id,
            'aud' => $app['client']->id,
            'iat' => time() - 3600,
            'exp' => time() - 2700,
        ]);

        $this->get('/connect/logout?'.http_build_query([
            'id_token_hint' => $expired,
            'post_logout_redirect_uri' => 'https://library.test/bye?from=sso',
            'state' => 'a b&c',
        ]))->assertRedirect('https://library.test/bye?from=sso&state=a%20b%26c');
        $this->assertGuest();

        $this->signInToSso(User::factory()->create());
        $this->post('/connect/logout', [
            'id_token_hint' => $tokens['id_token'],
            'client_id' => $app['client']->id,
            'post_logout_redirect_uri' => 'https://library.test/signed-out',
            'state' => 'xyz',
        ])->assertRedirect('https://library.test/signed-out?state=xyz');
        $this->assertGuest();
    }

    public function test_unregistered_post_logout_address_is_ignored(): void
    {
        Http::fake();
        $app = $this->appAt('library');
        $this->signInToSso(User::factory()->create());
        $tokens = $this->signInToApp($app);

        $this->get('/connect/logout?'.http_build_query([
            'id_token_hint' => $tokens['id_token'],
            'post_logout_redirect_uri' => 'https://evil.test/',
            'state' => 'xyz',
        ]))->assertRedirect('/login?logout');

        $this->assertGuest();
        $this->assertNull(session('sso.sid'));
        $this->assertSame(0, SignIn::count());
        Http::assertSentCount(1);
    }

    public function test_the_hint_must_name_the_app_that_sent_client_id(): void
    {
        Http::fake();
        $library = $this->appAt('library');
        $grades = $this->appAt('grades');
        $this->signInToSso(User::factory()->create());
        $tokens = $this->signInToApp($library);

        $this->get('/connect/logout?'.http_build_query([
            'id_token_hint' => $tokens['id_token'],
            'client_id' => $grades['client']->id,
            'post_logout_redirect_uri' => 'https://library.test/signed-out',
        ]))->assertRedirect('/login?logout');
        $this->assertGuest();
    }

    public function test_a_hint_that_does_not_verify_names_no_app(): void
    {
        Http::fake();
        $app = $this->appAt('library');
        $this->signInToSso(User::factory()->create());
        $tokens = $this->signInToApp($app);
        [$header, $payload] = explode('.', $tokens['id_token']);

        $this->get('/connect/logout?'.http_build_query([
            'id_token_hint' => "{$header}.{$payload}.c2lnbmF0dXJl",
            'post_logout_redirect_uri' => 'https://library.test/signed-out',
        ]))->assertRedirect('/login?logout');
        $this->assertGuest();
        $this->assertSame(0, SignIn::count());
    }

    public function test_logout_without_an_sso_session_just_redirects(): void
    {
        Http::fake();
        $app = $this->appAt('library');

        $this->get('/connect/logout')->assertRedirect('/login?logout');
        $this->post('/connect/logout', ['client_id' => $app['client']->id])->assertRedirect('/login?logout');
        $this->post('/logout')->assertRedirect('/login?logout');

        Http::assertNothingSent();
    }

    public function test_apps_can_post_the_logout_request_without_a_csrf_token(): void
    {
        $this->app['env'] = 'local';   // the CSRF check is skipped while running tests
        $this->signInToSso(User::factory()->create());

        $this->post('/logout')->assertStatus(419);
        $this->post('/connect/logout')->assertRedirect('/login?logout');
        $this->assertGuest();
    }

    public function test_a_logout_token_is_not_accepted_as_an_access_token(): void
    {
        Http::fake();
        $app = $this->appAt('library');
        $this->signInToSso(User::factory()->create());
        $this->signInToApp($app);
        $this->post('/logout');
        $logoutToken = $this->logoutTokens()['https://library.test/backchannel-logout'][0];

        $this->getJson('/userinfo', $this->bearer($logoutToken))->assertStatus(401);
    }

    public function test_a_session_whose_username_now_belongs_to_someone_else_ends(): void
    {
        $user = User::factory()->create(['username' => 'jdoe']);
        $this->signInToSso($user);
        $this->get('/')->assertOk();

        $user->update(['username' => 'jdoe-old']);
        User::factory()->create(['username' => 'JDoe']);

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNull(session('sso.sid'));
    }

    public function test_a_session_of_an_account_disabled_since_sign_in_ends(): void
    {
        $user = User::factory()->create();
        $this->signInToSso($user);
        $this->get('/')->assertOk();

        $user->update(['status_flag' => false]);

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_session_signed_in_as_another_user_id_ends(): void
    {
        $user = User::factory()->create();
        $this->signInToSso($user);
        $this->withSession(['sso.user_id' => $user->id + 1]);

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_session_check_leaves_the_session_less_endpoints_alone(): void
    {
        // The browser is signed in too: a session check here would find no session on the request.
        $tokens = $this->tokensFor(User::factory()->create());

        $this->getJson('/userinfo', $this->bearer($tokens['access_token']))->assertOk();
    }
}
