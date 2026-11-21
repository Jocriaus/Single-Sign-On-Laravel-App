<?php

namespace Tests\Feature\Accounts;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Role;
use App\Sso\Apps\ClientRegistrar;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClientRegistrarTest extends TestCase
{
    private function data(array $extra = []): array
    {
        return array_merge(['name' => 'Portal', 'redirect_uris' => 'https://a/cb'], $extra);
    }

    private function registrar(): ClientRegistrar
    {
        return app(ClientRegistrar::class);
    }

    private function assertInvalid(string $key, array $data): void
    {
        try {
            $this->registrar()->register($data);
            $this->fail("Expected a validation error on {$key}");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_registering_an_app_returns_its_secret_once_and_stores_only_a_hash(): void
    {
        $result = $this->registrar()->register($this->data());
        $client = $result['client']->fresh();

        $this->assertGreaterThanOrEqual(43, strlen($result['secret']));
        $this->assertTrue(Hash::check($result['secret'], $client->secret));
        $this->assertTrue(Str::isUuid($client->id));
        $this->assertSame(['authorization_code', 'refresh_token'], $client->grant_types);
        $this->assertTrue($client->require_pkce);
        $this->assertSame(1, ActivityLog::where('module', 'Apps')->where('activity_type', 'CREATE')->count());
    }

    public function test_scopes_default_to_openid_profile_email(): void
    {
        $client = $this->registrar()->register($this->data())['client'];
        $this->assertSame(['openid', 'profile', 'email'], $client->fresh()->scopes);
    }

    public function test_openid_is_always_added(): void
    {
        $client = $this->registrar()->register($this->data(['scopes' => 'email']))['client'];
        $this->assertSame(['openid', 'email'], $client->fresh()->scopes);
    }

    public function test_unknown_scope_is_refused(): void
    {
        $this->assertInvalid('scopes', $this->data(['scopes' => 'openid bogus']));
    }

    public function test_lists_accept_commas_or_spaces(): void
    {
        $client = $this->registrar()->register($this->data(['redirect_uris' => "https://a/cb, https://b/cb"]))['client'];
        $this->assertSame(['https://a/cb', 'https://b/cb'], $client->fresh()->redirect_uris);
    }

    public function test_a_redirect_address_is_required_and_must_be_an_absolute_http_address_without_a_fragment(): void
    {
        foreach ([[], '', 'ftp://x', 'https://a/cb#f', '/relative'] as $bad) {
            $this->assertInvalid('redirect_uris', $this->data(['redirect_uris' => $bad]));
        }
    }

    public function test_unknown_role_in_the_access_rule_is_refused(): void
    {
        foreach (['STUDENT', 'EMPLOYEE'] as $name) {
            Role::firstOrCreate(['role_name' => $name]);
        }
        $this->assertInvalid('allowed_roles', $this->data(['allowed_roles' => 'NOPE']));

        $client = $this->registrar()->register($this->data(['allowed_roles' => 'STUDENT, employee']))['client'];
        $this->assertSame(['STUDENT', 'EMPLOYEE'], $client->fresh()->allowed_roles);
    }

    public function test_empty_access_rule_means_everyone(): void
    {
        $client = $this->registrar()->register($this->data(['allowed_roles' => '']))['client'];
        $this->assertNull($client->fresh()->allowed_roles);
    }

    public function test_updating_keeps_the_id_and_secret(): void
    {
        $client = $this->registrar()->register($this->data())['client'];
        $hash = $client->fresh()->secret;

        $updated = $this->registrar()->update($client, $this->data(['name' => 'Renamed', 'backchannel_logout_uri' => 'https://a/bc', 'require_pkce' => false]));

        $fresh = Client::find($client->id);
        $this->assertSame($client->id, $updated->id);
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame($hash, $fresh->secret);
        $this->assertSame('https://a/bc', $fresh->backchannel_logout_uri);
        $this->assertFalse($fresh->require_pkce);
    }

    public function test_a_new_secret_stops_the_old_one(): void
    {
        $result = $this->registrar()->register($this->data());
        $new = $this->registrar()->resetSecret($result['client']);

        $fresh = Client::find($result['client']->id);
        $this->assertFalse(Hash::check($result['secret'], $fresh->secret));
        $this->assertTrue(Hash::check($new, $fresh->secret));
    }

    public function test_an_app_can_be_given_a_fixed_id_and_secret(): void
    {
        $client = $this->registrar()->register($this->data(), 'sso-admin-console', 'given-secret')['client'];

        $fresh = Client::find('sso-admin-console');
        $this->assertSame('sso-admin-console', $client->id);
        $this->assertNotNull($fresh);
        $this->assertTrue(Hash::check('given-secret', $fresh->secret));
    }

    public function test_removing_an_app_deletes_its_codes_and_tokens(): void
    {
        $client = $this->registrar()->register($this->data())['client'];
        \DB::table('oauth_access_tokens')->insert(['id' => 't1', 'client_id' => $client->id, 'revoked' => false]);
        \DB::table('oauth_refresh_tokens')->insert(['id' => 'r1', 'access_token_id' => 't1', 'revoked' => false]);
        \DB::table('oauth_auth_codes')->insert(['id' => 'c1', 'user_id' => 1, 'client_id' => $client->id, 'revoked' => false]);

        $this->registrar()->remove($client);

        $this->assertNull(Client::find($client->id));
        $this->assertSame(0, \DB::table('oauth_access_tokens')->count());
        $this->assertSame(0, \DB::table('oauth_refresh_tokens')->count());
        $this->assertSame(0, \DB::table('oauth_auth_codes')->count());
    }
}
