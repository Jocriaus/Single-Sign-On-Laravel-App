<?php

namespace Tests\Feature\Sso;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\SignIn;
use App\Models\User;
use App\Sso\Accounts\AutomaticRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository as PassportClientRepository;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class AppAccessTest extends TestCase
{
    use OAuthFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function student(): User
    {
        $user = User::factory()->student()->create();
        app(AutomaticRoles::class)->sync($user);

        return $user;
    }

    private function askToSignIn(User $user, Client $client)
    {
        $this->signInToSso($user);

        return $this->authorize($client, ['state' => 's', 'code_challenge' => $this->challenge(Str::random(64))]);
    }

    public function test_app_without_a_rule_lets_every_active_account_in(): void
    {
        $app = $this->registerApp();

        $this->codeFrom($this->askToSignIn($this->student(), $app['client']));
    }

    public function test_holder_of_an_allowed_role_gets_in(): void
    {
        $app = $this->registerApp(['allowed_roles' => 'STUDENT, EMPLOYEE']);

        $this->codeFrom($this->askToSignIn($this->student(), $app['client']));
    }

    public function test_others_see_the_no_access_page_and_the_app_gets_nothing(): void
    {
        $app = $this->registerApp(['name' => 'Library <b>', 'allowed_roles' => 'EMPLOYEE']);
        $user = $this->student();

        $response = $this->askToSignIn($user, $app['client']);

        $response->assertForbidden();
        $response->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('Location'));
        $response->assertSee("You don't have access to Library &lt;b&gt;", false);
        $response->assertSee("You're signed in as", false);
        $response->assertSee(e(\App\Sso\Accounts\UserClaims::displayName($user)), false);
        $response->assertSee("If you need it, ask the app's administrator.", false);
        $response->assertSee('Sign out');
        $response->assertSee(route('logout'), false);
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
        $this->assertSame(0, SignIn::count());
    }

    public function test_refusal_is_written_to_the_activity_log(): void
    {
        $app = $this->registerApp(['name' => 'Library', 'allowed_roles' => 'EMPLOYEE']);
        $user = $this->student();

        $this->askToSignIn($user, $app['client'])->assertForbidden();

        $this->assertTrue(ActivityLog::where('activity_type', 'AUTH')
            ->where('module', 'OAuth2')
            ->where('user_id', $user->id)
            ->where('activity', 'Refused sign-in to app Library: no allowed role')
            ->exists());
    }

    public function test_rule_tightened_after_sign_in_fails_the_next_refresh(): void
    {
        $app = $this->registerApp();
        $tokens = $this->tokensFor($this->student(), $app);

        $app['client']->forceFill(['allowed_roles' => ['EMPLOYEE']])->save();

        $this->refresh($app, $tokens['refresh_token'])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_grant', 'hint' => 'This account does not have access to this app.']);
    }

    public function test_role_removed_after_sign_in_fails_the_next_refresh(): void
    {
        $app = $this->registerApp(['allowed_roles' => 'STUDENT, EMPLOYEE']);
        $user = $this->student();
        $tokens = $this->tokensFor($user, $app);

        $user->roles()->detach();

        $this->refresh($app, $tokens['refresh_token'])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_grant', 'hint' => 'This account does not have access to this app.']);
    }

    public function test_an_app_whose_record_cannot_be_read_is_refused(): void
    {
        $app = $this->registerApp(['allowed_roles' => 'EMPLOYEE']);
        // Passport still validates the request against the app, but this server's own lookup finds nothing.
        $this->app->instance(PassportClientRepository::class, new class($app['client']) extends PassportClientRepository
        {
            public function __construct(private Client $client)
            {
            }

            public function find(string|int $id): ?Client
            {
                return $this->client;
            }
        });
        $app['client']->delete();

        $response = $this->askToSignIn($this->student(), $app['client']);

        $response->assertStatus(400)->assertViewIs('oauth-error');
        $this->assertNull($response->headers->get('Location'));
        $this->assertSame(0, SignIn::count());
        $this->assertSame(0, DB::table('oauth_auth_codes')->count());
    }

    public function test_the_admin_console_is_never_subject_to_app_rules(): void
    {
        $this->registerApp(['allowed_roles' => 'STUDENT, EMPLOYEE']);
        $admin = User::factory()->adminType()->withRole('ICTO_ADMIN')->create();

        $this->signInToSso($admin)->get('/')->assertOk();
    }
}
