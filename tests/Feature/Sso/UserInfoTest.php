<?php

namespace Tests\Feature\Sso;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class UserInfoTest extends TestCase
{
    use OAuthFlow;

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_userinfo_returns_claims_for_the_scopes_granted(): void
    {
        $user = User::factory()->student()->withRole('STUDENT')->create(['email' => 'a@b.test']);
        $tokens = $this->tokensFor($user);

        $body = $this->getJson('/userinfo', $this->bearer($tokens['access_token']))->assertOk()->json();

        $this->assertSame((string) $user->id, $body['sub']);
        $this->assertSame($user->id, $body['user_id']);
        $this->assertSame('Student', $body['user_type']);
        $this->assertSame(['STUDENT'], $body['roles']);
        $this->assertSame($user->username, $body['preferred_username']);
        $this->assertSame('a@b.test', $body['email']);
        foreach (['first_name', 'last_name', 'middle_name', 'middle_initial', 'studentNo'] as $key) {
            $this->assertArrayHasKey($key, $body['user_profile']);
        }

        $narrow = $this->tokensFor($user, null, 'openid');
        $body = $this->getJson('/userinfo', $this->bearer($narrow['access_token']))->assertOk()->json();
        $keys = array_keys($body);
        sort($keys);
        $this->assertSame(['roles', 'sub', 'user_id', 'user_type'], $keys);
    }

    public function test_userinfo_also_answers_post(): void
    {
        $tokens = $this->tokensFor(User::factory()->create());

        $this->postJson('/userinfo', [], $this->bearer($tokens['access_token']))->assertOk()->assertJsonStructure(['sub']);
    }

    public function test_userinfo_shows_both_numbers_when_a_person_has_both(): void
    {
        $user = User::factory()->student()->create(['employee_no' => 'E-000001']);
        $tokens = $this->tokensFor($user);

        $profile = $this->getJson('/userinfo', $this->bearer($tokens['access_token']))->json('user_profile');

        $this->assertSame($user->student_no, $profile['studentNo']);
        $this->assertSame('E-000001', $profile['employeeNo']);
    }

    public function test_userinfo_without_a_token_is_401(): void
    {
        $this->getJson('/userinfo')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertExactJson(['error' => 'invalid_token']);
    }

    public function test_userinfo_without_a_token_does_not_redirect_a_browser_request(): void
    {
        $this->get('/userinfo')->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function test_userinfo_refuses_a_token_after_sign_out_revoked_it(): void
    {
        $tokens = $this->tokensFor(User::factory()->create());
        DB::table('oauth_access_tokens')->update(['revoked' => true]);

        $this->getJson('/userinfo', $this->bearer($tokens['access_token']))->assertStatus(401);
    }

    public function test_userinfo_refuses_a_user_who_became_disabled(): void
    {
        $user = User::factory()->create();
        $tokens = $this->tokensFor($user);
        $user->update(['status_flag' => false]);

        $this->getJson('/userinfo', $this->bearer($tokens['access_token']))
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer');
    }
}
