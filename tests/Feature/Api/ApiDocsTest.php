<?php

namespace Tests\Feature\Api;

use App\Console\Commands\SsoInstall;
use App\Models\ApiAuditLog;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    use OAuthFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['sso.admin_client_secret' => str_repeat('s', 32)]);
    }

    private function document(): array
    {
        $user = User::factory()->employee()->withRole('ICTO_ADMIN')->create();

        return $this->signInToSso($user)->get('/docs/api.json')->assertOk()->json();
    }

    public function test_guests_are_sent_to_login_and_ordinary_users_get_403(): void
    {
        foreach (['/docs/api', '/docs/api.json'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }

        $student = User::factory()->student()->create();
        foreach (['/docs/api', '/docs/api.json'] as $path) {
            $this->signInToSso($student)->get($path)->assertForbidden();
        }
    }

    public function test_administrators_can_open_the_page_and_the_document(): void
    {
        foreach (['ICTO_ADMIN', 'ADMIN'] as $role) {
            $user = User::factory()->employee()->withRole($role)->create();
            foreach (['/docs/api', '/docs/api.json'] as $path) {
                $this->signInToSso($user)->get($path)->assertOk();
            }
        }
    }

    public function test_document_lists_the_api_and_how_to_sign_in(): void
    {
        $doc = $this->document();
        $issuer = config('sso.issuer');

        $this->assertStringStartsWith('3.1', $doc['openapi']);
        foreach (['/v1/user/me', '/v1/client', '/v1/admin/user/{id}'] as $path) {
            $this->assertArrayHasKey($path, $doc['paths']);
        }

        $flow = $doc['components']['securitySchemes']['oauth2']['flows']['authorizationCode'];
        $this->assertSame("$issuer/oauth2/authorize", $flow['authorizationUrl']);
        $this->assertSame("$issuer/oauth2/token", $flow['tokenUrl']);
        $this->assertEqualsCanonicalizing(['openid', 'profile', 'email', 'sso.admin'], array_keys($flow['scopes']));
    }

    public function test_document_does_not_list_the_sign_in_pages(): void
    {
        $paths = array_keys($this->document()['paths']);

        foreach (['/login', '/oauth2/token', '/admin'] as $path) {
            $this->assertNotContains($path, $paths);
        }
    }

    public function test_the_documentation_pages_are_not_audited_as_api_calls(): void
    {
        $user = User::factory()->employee()->withRole('ICTO_ADMIN')->create();
        $this->signInToSso($user)->get('/docs/api')->assertOk();
        $this->signInToSso($user)->get('/docs/api.json')->assertOk();

        $this->assertSame(0, ApiAuditLog::count());
    }

    public function test_install_creates_the_admin_client_when_a_secret_is_set(): void
    {
        $this->artisan('sso:install')
            ->expectsOutputToContain("Created the 'sso-admin-console' client for the API documentation page.")
            ->assertSuccessful();

        $client = Client::findOrFail(SsoInstall::ADMIN_CLIENT_ID);
        $this->assertTrue(Hash::check(str_repeat('s', 32), $client->secret));
        $this->assertContains('sso.admin', $client->scopes);
        $this->assertSame([config('sso.issuer').'/docs/api'], $client->redirect_uris);
        $this->assertSame(['ICTO_ADMIN', 'ADMIN'], $client->allowed_roles);
        $this->assertTrue((bool) $client->require_pkce);
    }

    public function test_install_never_changes_an_existing_admin_client(): void
    {
        $this->artisan('sso:install')->assertSuccessful();
        config(['sso.admin_client_secret' => str_repeat('n', 32)]);

        $this->artisan('sso:install')
            ->expectsOutputToContain('Admin console client already exists; left unchanged.')
            ->assertSuccessful();

        $client = Client::findOrFail(SsoInstall::ADMIN_CLIENT_ID);
        $this->assertTrue(Hash::check(str_repeat('s', 32), $client->secret));
        $this->assertFalse(Hash::check(str_repeat('n', 32), $client->secret));
    }

    public function test_install_skips_the_admin_client_when_no_secret_is_set(): void
    {
        config(['sso.admin_client_secret' => '']);

        $this->artisan('sso:install')
            ->expectsOutputToContain('Admin console client not created: SSO_ADMIN_CLIENT_SECRET is not set.')
            ->assertSuccessful();

        $this->assertNull(Client::find(SsoInstall::ADMIN_CLIENT_ID));
    }

    public function test_install_asks_for_the_seeder_when_the_roles_are_missing(): void
    {
        Role::query()->delete();

        $this->artisan('sso:install')
            ->expectsOutputToContain('Run the database seeder first (php artisan db:seed).')
            ->assertFailed();

        $this->assertNull(Client::find(SsoInstall::ADMIN_CLIENT_ID));
    }

    public function test_a_token_from_the_admin_client_can_call_the_admin_api(): void
    {
        $this->artisan('sso:install')->assertSuccessful();
        $client = Client::findOrFail(SsoInstall::ADMIN_CLIENT_ID);
        $admin = User::factory()->employee()->withRole('ICTO_ADMIN')->create();

        $tokens = $this->tokensFor($admin, ['client' => $client, 'secret' => str_repeat('s', 32)], 'openid sso.admin');

        $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])
            ->getJson('/api/v1/client/')
            ->assertOk();
    }
}
