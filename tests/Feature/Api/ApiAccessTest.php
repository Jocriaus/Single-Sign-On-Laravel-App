<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class ApiAccessTest extends TestCase
{
    use OAuthFlow;

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_me_returns_the_current_users_profile(): void
    {
        $user = User::factory()->student()->withRole('STUDENT')->create(['suffix' => 'Jr.']);
        $tokens = $this->tokensFor($user);

        $response = $this->getJson('/api/v1/user/me', $this->bearer($tokens['access_token']))
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Profile loaded.', 'status' => 200, 'errorCode' => null, 'error' => null]);

        $data = $response->json('data');
        $this->assertSame($user->username, $data['username']);
        $this->assertSame(['STUDENT'], $data['roles']);
        $this->assertSame($user->id, $data['id']);
        $this->assertSame('Jr.', $data['suffix']);
        $this->assertSame($user->student_no, $data['studentNumber']);
        $this->assertNull($data['employeeNumber']);
        foreach (['email', 'userType', 'name', 'firstName', 'middleName', 'middleInitial', 'lastName'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertNotEmpty($response->json('timestamp'));
    }

    public function test_me_refuses_a_user_who_became_disabled(): void
    {
        $user = User::factory()->create();
        $tokens = $this->tokensFor($user);
        $user->update(['status_flag' => false]);

        $this->getJson('/api/v1/user/me', $this->bearer($tokens['access_token']))
            ->assertStatus(401)->assertJson(['errorCode' => 'UNAUTHORIZED']);
    }

    public function test_api_without_a_token_is_401_json_in_the_envelope(): void
    {
        $this->getJson('/api/v1/user/me')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'status' => 401,
                'errorCode' => 'UNAUTHORIZED',
                'message' => 'Sign in with an access token from this SSO server.',
                'error' => 'Unauthorized',
            ]);
    }

    public function test_api_without_a_token_is_401_json_even_without_an_accept_header(): void
    {
        $this->get('/api/v1/user/me')->assertStatus(401)->assertJson(['errorCode' => 'UNAUTHORIZED']);
    }

    public function test_an_id_token_is_not_accepted_as_an_access_token(): void
    {
        $tokens = $this->tokensFor(User::factory()->create());

        $this->getJson('/api/v1/user/me', $this->bearer($tokens['id_token']))->assertStatus(401);
        $this->getJson('/userinfo', $this->bearer($tokens['id_token']))->assertStatus(401);
    }

    public function test_unknown_api_address_is_404_json(): void
    {
        $response = $this->getJson('/api/v1/nothing-here')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'errorCode' => 'NOT_FOUND', 'message' => 'Not found.', 'status' => 404]);

        $this->assertStringNotContainsString('could not be found', $response->getContent());
        $this->assertStringNotContainsString('Exception', $response->getContent());
    }

    public function test_wrong_method_is_405_in_the_envelope(): void
    {
        $tokens = $this->tokensFor(User::factory()->create());

        $this->postJson('/api/v1/user/me', [], $this->bearer($tokens['access_token']))
            ->assertStatus(405)->assertJson(['errorCode' => 'METHOD_NOT_ALLOWED']);
    }

    public function test_unexpected_faults_are_500_without_detail_even_in_debug(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/v1/boom', fn () => throw new \RuntimeException('secret detail'));

        $response = $this->getJson('/api/v1/boom')
            ->assertStatus(500)
            ->assertJson(['errorCode' => 'INTERNAL_ERROR', 'message' => 'Something went wrong.']);

        $this->assertStringNotContainsString('secret detail', $response->getContent());
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
    }

    public function test_validation_failures_are_422_with_field_errors_in_data(): void
    {
        Route::post('/api/v1/check', fn (Request $r) => $r->validate(['name' => 'required']));

        $this->postJson('/api/v1/check', [])
            ->assertStatus(422)
            ->assertJson(['errorCode' => 'VALIDATION_FAILED', 'status' => 422])
            ->assertJsonStructure(['data' => ['name']]);
    }

    public function test_other_http_errors_keep_their_real_status(): void
    {
        Route::get('/api/v1/odd', fn () => abort(415));
        Route::get('/api/v1/down', fn () => abort(503));

        $this->getJson('/api/v1/odd')->assertStatus(415)
            ->assertJson(['errorCode' => 'REQUEST_FAILED', 'message' => 'Unsupported Media Type', 'status' => 415]);
        $this->getJson('/api/v1/down')->assertStatus(503)
            ->assertJson(['errorCode' => 'INTERNAL_ERROR', 'message' => 'Something went wrong.', 'status' => 503]);
    }

    public function test_the_bare_api_address_is_the_json_404(): void
    {
        $this->get('/api')->assertStatus(404)->assertJson(['errorCode' => 'NOT_FOUND', 'success' => false]);
    }
}
