<?php

namespace Tests\Feature\Sso;

use App\Models\Client;
use App\Models\Role;
use App\Models\SignIn;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

/** What the OpenID Foundation conformance suite (Basic OP) asks of the authorization endpoint. */
class ConformanceAuthorizeTest extends TestCase
{
    use OAuthFlow;

    private const PASSWORD = 'correct-horse-battery';

    /** @return array{0: array<string, string|null>, 1: string} authorize parameters with a new PKCE pair, and its verifier */
    private function pkce(array $params = []): array
    {
        $verifier = Str::random(64);

        return [$params + [
            'state' => 'st-'.Str::random(8),
            'nonce' => 'n-'.Str::random(8),
            'code_challenge' => $this->challenge($verifier),
        ], $verifier];
    }

    /** The full parameter set the app sends, as authorize() builds it. */
    private function fullParams(Client $client, array $params): array
    {
        return array_filter($params + [
            'response_type' => 'code',
            'client_id' => $client->id,
            'redirect_uri' => $client->redirect_uris[0],
            'scope' => 'openid profile email',
            'code_challenge_method' => 'S256',
        ], fn ($value) => $value !== null);
    }

    private function idToken(array $app, TestResponse $response, string $verifier): array
    {
        $tokens = $this->exchange($app['client'], $app['secret'], $this->codeFrom($response), $verifier)->assertOk();

        return $this->claims($tokens->json('id_token'));
    }

    /** Types the password on the login page; the browser is then sent back to the saved authorize request. */
    private function typePassword(User $user): TestResponse
    {
        return $this->post('/login', ['username' => $user->username, 'password' => self::PASSWORD])
            ->assertRedirectContains('/oauth2/authorize');
    }

    private function follow(TestResponse $response): TestResponse
    {
        return $this->get($response->headers->get('Location'));
    }

    private function errorAtTheApp(TestResponse $response, string $error, ?string $state): void
    {
        $response->assertRedirectContains('https://app.test/cb?');
        $query = $this->redirectQuery($response);
        $this->assertSame($error, $query['error'] ?? null, $response->headers->get('Location'));
        $this->assertSame($state, $query['state'] ?? null);
        $this->assertArrayNotHasKey('code', $query);
    }

    public function test_prompt_none_without_a_session_returns_login_required_to_the_app(): void
    {
        $app = $this->registerApp();
        $state = Str::random(150).' +/=&?#%'.Str::random(142);
        $this->assertSame(300, strlen($state));

        $response = $this->authorize($app['client'], [
            'prompt' => 'none',
            'state' => $state,
            'code_challenge' => $this->challenge(Str::random(64)),
        ]);

        $this->errorAtTheApp($response, 'login_required', $state);
        $this->assertGuest();
        $this->assertSame(0, SignIn::count());
    }

    public function test_prompt_none_with_a_session_signs_in_silently(): void
    {
        $user = User::factory()->create();
        $app = $this->registerApp();
        $first = $this->claims($this->tokensFor($user, $app)['id_token']);
        [$params, $verifier] = $this->pkce(['prompt' => 'none']);

        $id = $this->idToken($app, $this->authorize($app['client'], $params), $verifier);

        $this->assertSame((string) $user->id, $id['sub']);
        $this->assertSame($first['sub'], $id['sub']);
        $this->assertSame($first['auth_time'], $id['auth_time']);
    }

    public function test_prompt_login_asks_for_the_password_again_and_gives_a_later_auth_time(): void
    {
        $user = User::factory()->create();
        $app = $this->registerApp();
        $first = $this->claims($this->tokensFor($user, $app)['id_token']);
        [$params, $verifier] = $this->pkce(['prompt' => 'login']);

        $this->authorize($app['client'], $params)->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertViewIs('login')->assertSee('Test App');
        $this->travel(5)->seconds();
        $id = $this->idToken($app, $this->follow($this->typePassword($user)), $verifier);

        $this->assertGreaterThan($first['auth_time'], $id['auth_time']);
        $this->assertSame(now()->getTimestamp(), $id['auth_time']);
        $this->assertSame($first['sid'], $id['sid']);
        $this->assertSame($params['nonce'], $id['nonce']);
        // The fresh sign-in was used up: the same request asks again.
        $this->authorize($app['client'], $params)->assertRedirect('/login');
    }

    public function test_max_age_exceeded_asks_for_the_password_again(): void
    {
        $user = User::factory()->create();
        $app = $this->registerApp();
        $this->signInToSso($user);
        $this->travel(10)->seconds();
        [$params, $verifier] = $this->pkce(['max_age' => '1']);

        $this->authorize($app['client'], $params)->assertRedirect('/login');
        $id = $this->idToken($app, $this->follow($this->typePassword($user)), $verifier);

        $this->assertEqualsWithDelta(now()->getTimestamp(), $id['auth_time'], 2);
    }

    public function test_max_age_not_exceeded_signs_in_silently(): void
    {
        $user = User::factory()->create();
        $app = $this->registerApp();
        $this->signInToSso($user);
        [$params, $verifier] = $this->pkce(['max_age' => '10000']);

        $id = $this->idToken($app, $this->authorize($app['client'], $params), $verifier);

        $this->assertArrayHasKey('auth_time', $id);
        $this->assertSame(session('sso.auth_time'), $id['auth_time']);
    }

    public function test_reauthentication_does_not_loop(): void
    {
        $user = User::factory()->create();
        $app = $this->registerApp();
        $this->signInToSso($user);
        $this->travel(10)->seconds();
        [$params, $verifier] = $this->pkce(['max_age' => '1']);

        $this->authorize($app['client'], $params)->assertRedirect('/login');
        $back = $this->typePassword($user);
        $this->travel(2)->seconds();

        $this->idToken($app, $this->follow($back), $verifier);
    }

    public function test_post_to_the_authorize_endpoint_works(): void
    {
        $user = User::factory()->create();
        $app = $this->registerApp();
        [$params, $verifier] = $this->pkce();
        $form = $this->fullParams($app['client'], $params);
        $this->app['env'] = 'local';   // the CSRF check is skipped while running tests; apps post without a token

        // Without a session: the request is resumed as a GET after the password.
        $this->post('/oauth2/authorize', $form)->assertRedirect('/login');
        $this->app['env'] = 'testing';
        $id = $this->idToken($app, $this->follow($this->typePassword($user)), $verifier);
        $this->assertSame($params['nonce'], $id['nonce']);

        // With a session.
        [$params, $verifier] = $this->pkce();
        $this->app['env'] = 'local';
        $response = $this->post('/oauth2/authorize', $this->fullParams($app['client'], $params));
        $this->app['env'] = 'testing';

        $this->assertSame($params['state'], $this->redirectQuery($response)['state']);
        $this->assertSame($params['nonce'], $this->idToken($app, $response, $verifier)['nonce']);
    }

    public function test_optional_parameters_are_accepted_and_ignored(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());
        [$params, $verifier] = $this->pkce([
            'id_token_hint' => 'not.a.token',
            'login_hint' => 'someone@example.test',
            'ui_locales' => 'fil en',
            'claims_locales' => 'en',
            'acr_values' => '1 2',
            'display' => 'popup',
            'claims' => json_encode(['id_token' => ['email' => ['essential' => true]]]),
            'foo' => 'bar',
        ]);

        $response = $this->authorize($app['client'], $params);

        $this->assertSame($params['state'], $this->redirectQuery($response)['state']);
        $this->idToken($app, $response, $verifier);
    }

    public function test_request_objects_are_refused_as_unsupported(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());

        foreach (['request' => ['x', 'request_not_supported'], 'request_uri' => ['https://x', 'request_uri_not_supported']] as $name => [$value, $error]) {
            $response = $this->authorize($app['client'], [
                $name => $value,
                'state' => "s-$name",
                'code_challenge' => $this->challenge(Str::random(64)),
            ]);

            $this->errorAtTheApp($response, $error, "s-$name");
        }
        $this->assertSame(0, SignIn::count());
    }

    /** No response_type: the library refuses before it has checked the return address, so this server shows its error page. */
    public function test_missing_response_type_is_an_error_not_a_crash(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());

        $response = $this->authorize($app['client'], [
            'response_type' => null,
            'state' => 's-1',
            'code_challenge' => $this->challenge(Str::random(64)),
        ]);

        $response->assertStatus(400)->assertViewIs('oauth-error');
        $this->assertFalse($response->headers->has('Location'));
        $this->assertSame(0, SignIn::count());
    }

    public function test_an_app_with_pkce_switched_off_can_sign_in_without_it(): void
    {
        $app = $this->registerApp(['require_pkce' => false]);
        $this->signInToSso(User::factory()->create());
        $token = fn (string $code, array $extra = []) => $this->post('/oauth2/token', $extra + [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $app['client']->redirect_uris[0],
            'client_id' => $app['client']->id,
            'client_secret' => $app['secret'],
        ]);

        $code = $this->codeFrom($this->authorize($app['client'], ['state' => 's-1', 'code_challenge_method' => null]));
        $token($code)->assertOk()->assertJsonStructure(['access_token', 'id_token', 'refresh_token']);

        // A verifier for a code issued without a challenge is a downgrade attempt.
        $code = $this->codeFrom($this->authorize($app['client'], ['state' => 's-2', 'code_challenge_method' => null]));
        $token($code, ['code_verifier' => Str::random(64)])->assertStatus(400)->assertJson(['error' => 'invalid_request']);
    }

    public function test_an_app_with_pkce_switched_off_may_still_use_it(): void
    {
        $app = $this->registerApp(['require_pkce' => false]);
        $this->signInToSso(User::factory()->create());

        [$params, $verifier] = $this->pkce();
        $code = $this->codeFrom($this->authorize($app['client'], $params));
        $this->exchange($app['client'], $app['secret'], $code, $verifier)->assertOk();

        [$params] = $this->pkce();
        $code = $this->codeFrom($this->authorize($app['client'], $params));
        $this->exchange($app['client'], $app['secret'], $code, Str::random(64))
            ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_pkce_stays_required_by_default(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());

        $this->assertTrue($app['client']->fresh()->require_pkce);
        $this->errorAtTheApp($this->authorize($app['client'], ['state' => 's-1']), 'invalid_request', 's-1');
    }

    public function test_a_request_without_nonce_gets_an_id_token_without_nonce(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());
        [$params, $verifier] = $this->pkce(['nonce' => null]);

        $id = $this->idToken($app, $this->authorize($app['client'], $params), $verifier);

        $this->assertArrayNotHasKey('nonce', $id);
    }

    public function test_reusing_a_code_revokes_the_tokens_it_produced(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());
        [$params, $verifier] = $this->pkce();
        $code = $this->codeFrom($this->authorize($app['client'], $params));
        $tokens = $this->exchange($app['client'], $app['secret'], $code, $verifier)->assertOk()->json();

        $this->exchange($app['client'], $app['secret'], $code, $verifier)
            ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);

        $this->getJson('/userinfo', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertStatus(401);
        $this->refresh($app, $tokens['refresh_token'])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        // The SSO session is untouched: the app can simply ask again.
        $this->assertSame(1, SignIn::count());
        [$params, $verifier] = $this->pkce();
        $this->idToken($app, $this->authorize($app['client'], $params), $verifier);
    }

    public function test_reusing_a_code_after_signing_out_is_refused_harmlessly(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());
        [$params, $verifier] = $this->pkce();
        $code = $this->codeFrom($this->authorize($app['client'], $params));
        $this->exchange($app['client'], $app['secret'], $code, $verifier)->assertOk();
        $this->post('/logout');

        $this->exchange($app['client'], $app['secret'], $code, $verifier)
            ->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    }

    public function test_prompt_none_for_a_user_who_must_change_password_returns_interaction_required(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->mustChangePassword()->create());
        [$params] = $this->pkce(['prompt' => 'none']);

        $this->errorAtTheApp($this->authorize($app['client'], $params), 'interaction_required', $params['state']);
        $this->assertSame(0, SignIn::count());
    }

    public function test_prompt_none_for_a_user_the_app_refuses_returns_access_denied(): void
    {
        Role::firstOrCreate(['role_name' => 'EMPLOYEE']);
        $app = $this->registerApp(['allowed_roles' => 'EMPLOYEE']);
        $this->signInToSso(User::factory()->create());
        [$params] = $this->pkce(['prompt' => 'none']);

        $this->errorAtTheApp($this->authorize($app['client'], $params), 'access_denied', $params['state']);
        $this->assertSame(0, SignIn::count());
    }

    public function test_prompt_none_for_a_session_its_account_no_longer_owns_returns_login_required(): void
    {
        $app = $this->registerApp();
        $user = User::factory()->create();
        $this->signInToSso($user);
        $user->update(['status_flag' => false]);
        [$params] = $this->pkce(['prompt' => 'none']);

        $this->errorAtTheApp($this->authorize($app['client'], $params), 'login_required', $params['state']);
        $this->assertGuest();
        $this->assertNull(session('sso.sid'));
    }

    public function test_prompt_none_with_another_value_and_a_malformed_max_age_are_invalid(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());

        foreach ([['prompt' => 'none login'], ['max_age' => '-1'], ['max_age' => 'abc'], ['max_age' => '1.5']] as $bad) {
            [$params] = $this->pkce($bad);
            $this->errorAtTheApp($this->authorize($app['client'], $params), 'invalid_request', $params['state']);
        }
        $this->assertSame(0, SignIn::count());
    }

    public function test_max_age_zero_always_asks_for_the_password(): void
    {
        $user = User::factory()->create();
        $app = $this->registerApp();
        $this->signInToSso($user);
        [$params, $verifier] = $this->pkce(['max_age' => '0']);

        $this->authorize($app['client'], $params)->assertRedirect('/login');
        $this->idToken($app, $this->follow($this->typePassword($user)), $verifier);
    }

    public function test_coming_back_without_typing_the_password_asks_again(): void
    {
        $app = $this->registerApp();
        $this->signInToSso(User::factory()->create());
        [$params] = $this->pkce(['prompt' => 'login']);

        $this->authorize($app['client'], $params)->assertRedirect('/login');
        $this->authorize($app['client'], $params)->assertRedirect('/login');
        $this->assertSame(0, SignIn::count());
    }

    public function test_prompt_login_for_a_guest_asks_for_the_password_once(): void
    {
        $user = User::factory()->create();
        $app = $this->registerApp();
        [$params, $verifier] = $this->pkce(['prompt' => 'login', 'max_age' => '1']);

        $this->authorize($app['client'], $params)->assertRedirect('/login');
        $back = $this->typePassword($user);
        $this->travel(2)->seconds();

        $this->idToken($app, $this->follow($back), $verifier);
    }

    public function test_another_account_typing_its_password_when_asked_again_ends_the_first_session(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $app = $this->registerApp();
        $old = $this->tokensFor($first, $app);
        $oldSid = $this->claims($old['id_token'])['sid'];
        [$params, $verifier] = $this->pkce(['prompt' => 'login']);

        $this->authorize($app['client'], $params)->assertRedirect('/login');
        $back = $this->typePassword($second);

        $this->getJson('/userinfo', ['Authorization' => 'Bearer '.$old['access_token']])->assertStatus(401);
        $this->assertSame(0, SignIn::where('user_id', $first->id)->count());
        $id = $this->idToken($app, $this->follow($back), $verifier);
        $this->assertSame((string) $second->id, $id['sub']);
        $this->assertNotSame($oldSid, $id['sid']);
    }
}
