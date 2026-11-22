<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\UserType;
use App\Sso\Accounts\AutomaticRoles;
use App\Sso\Activity;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use OAuthFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function icto(): User
    {
        return User::factory()->adminType()->withRole('ICTO_ADMIN')->create();
    }

    private function adminLevel(): User
    {
        return User::factory()->adminType()->withRole('ADMIN')->create();
    }

    private function tokenFor(User $user, bool $adminScope = true): string
    {
        if (! $adminScope) {
            return $this->tokensFor($user)['access_token'];
        }

        $app = $this->registerApp(['scopes' => 'openid profile email sso.admin']);

        return $this->tokensFor($user, $app, 'openid profile email sso.admin')['access_token'];
    }

    private function userBody(array $over = []): array
    {
        return $over + [
            'userType' => 'Student', 'studentNumber' => '2026-555555', 'email' => 'api.person@bulsu.test',
            'firstName' => 'Api', 'lastName' => 'Person',
        ];
    }

    public function test_icto_admin_with_the_admin_scope_gets_in(): void
    {
        $token = $this->tokenFor($this->icto());

        $this->withToken($token)->getJson('/api/v1/admin/user/all')->assertOk()
            ->assertJson(['success' => true])->assertJsonStructure(['data' => ['items', 'page', 'size', 'total']]);
        $this->withToken($token)->getJson('/api/v1/admin/logs')->assertOk();
    }

    public function test_admin_user_whose_token_lacks_the_admin_scope_is_refused(): void
    {
        $token = $this->tokenFor($this->icto(), false);

        $this->withToken($token)->getJson('/api/v1/admin/user/all')
            ->assertStatus(403)->assertJson(['errorCode' => 'FORBIDDEN', 'message' => "You don't have access to this."]);
    }

    public function test_ordinary_user_with_the_admin_scope_is_refused(): void
    {
        $student = User::factory()->student()->create();
        app(AutomaticRoles::class)->sync($student);

        $this->withToken($this->tokenFor($student))->getJson('/api/v1/admin/user/all')
            ->assertStatus(403)->assertJson(['errorCode' => 'FORBIDDEN']);
    }

    public function test_admin_level_reaches_user_routes_but_not_client_role_or_log_routes(): void
    {
        $token = $this->tokenFor($this->adminLevel());

        $this->withToken($token)->getJson('/api/v1/admin/user/all')->assertOk();
        $this->withToken($token)->getJson('/api/v1/client/')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/admin/role/all')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/admin/logs')->assertStatus(403);
        $this->withToken($token)->postJson('/api/v1/client', ['name' => 'X', 'redirectUris' => ['https://x.test/cb']])->assertStatus(403);
    }

    public function test_admin_level_cannot_act_on_a_protected_account_through_the_api(): void
    {
        $icto = $this->icto();
        $token = $this->tokenFor($this->adminLevel());

        $this->withToken($token)->putJson('/api/v1/admin/user/'.$icto->id.'/lock')
            ->assertStatus(403)->assertJson(['errorCode' => 'FORBIDDEN']);
        $this->assertTrue($icto->fresh()->status_flag);
    }

    public function test_role_removed_since_the_token_was_issued_is_refused_at_once(): void
    {
        $icto = $this->icto();
        $token = $this->tokenFor($icto);
        $this->withToken($token)->getJson('/api/v1/admin/role/all')->assertOk();

        $icto->roles()->detach(Role::where('role_name', 'ICTO_ADMIN')->value('id'));

        $this->withToken($token)->getJson('/api/v1/admin/role/all')->assertStatus(403)->assertJson(['errorCode' => 'FORBIDDEN']);
    }

    public function test_registering_an_app_returns_id_and_secret_and_validates_input(): void
    {
        $token = $this->tokenFor($this->icto());

        $response = $this->withToken($token)->postJson('/api/v1/client', [
            'name' => 'Library', 'redirectUris' => ['https://lib.test/cb'], 'scopes' => ['openid', 'email'],
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $data = $response->json('data');
        $this->assertSame('Library', $data['clientName']);
        $this->assertNotEmpty($data['clientId']);
        $this->assertNotEmpty($data['clientSecret']);

        $bad = $this->withToken($token)->postJson('/api/v1/client', [
            'name' => 'Bad', 'redirectUris' => ['https://x.test/cb'], 'scopes' => ['openid', 'nope'],
        ])->assertStatus(422)->assertJson(['errorCode' => 'VALIDATION_FAILED']);
        $this->assertArrayHasKey('scopes', $bad->json('data'));

        $this->withToken($token)->postJson('/api/v1/client', ['name' => 'No redirect'])
            ->assertStatus(422)->assertJsonStructure(['data' => ['redirectUris']]);

        $this->withToken($token)->getJson('/api/v1/client/'.$data['clientId'])->assertOk()
            ->assertJsonPath('data.clientName', 'Library')->assertJsonPath('data.redirectUris', ['https://lib.test/cb']);
        $this->withToken($token)->getJson('/api/v1/client/nope')->assertStatus(404)->assertJson(['errorCode' => 'NOT_FOUND']);
        $this->withToken($token)->getJson('/api/v1/client/paginated?size=1')->assertOk()->assertJsonPath('data.size', 1);
        $this->withToken($token)->getJson('/api/v1/client/')->assertOk();

        $this->withToken($token)->deleteJson('/api/v1/client/'.$data['clientId'])->assertOk();
        $this->assertNull(Client::find($data['clientId']));
    }

    public function test_creating_a_user_validates_and_returns_the_user(): void
    {
        $token = $this->tokenFor($this->icto());

        $response = $this->withToken($token)->postJson('/api/v1/admin/user', $this->userBody())
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $data = $response->json('data');
        $this->assertSame('2026-555555', $data['username']);
        $this->assertSame('Student', $data['userType']);
        $this->assertSame('2026-555555', $data['studentNumber']);
        $this->assertSame(['STUDENT'], $data['roles']);
        $this->assertTrue($data['mustChangePassword']);
        $this->assertNotEmpty($data['temporaryPassword']);

        $invalid = $this->withToken($token)->postJson('/api/v1/admin/user', $this->userBody(['email' => 'nope', 'studentNumber' => null]))
            ->assertStatus(422);
        $this->assertArrayHasKey('email', $invalid->json('data'));
        $this->assertArrayHasKey('studentNumber', $invalid->json('data'));

        $typeId = UserType::where('user_type_name', 'Employee')->value('id');
        $this->withToken($token)->postJson('/api/v1/admin/user', [
            'userTypeId' => $typeId, 'employeeNumber' => 'E-9', 'email' => 'e9@bulsu.test', 'firstName' => 'E', 'lastName' => 'Nine',
        ])->assertOk()->assertJsonPath('data.userType', 'Employee');

        $id = $data['id'];
        $this->withToken($token)->getJson('/api/v1/admin/user/'.$id)->assertOk()->assertJsonMissingPath('data.temporaryPassword');
        $this->withToken($token)->putJson('/api/v1/admin/user/'.$id, $this->userBody(['firstName' => 'Changed']))
            ->assertOk()->assertJsonPath('data.firstName', 'Changed');
        $this->withToken($token)->getJson('/api/v1/admin/user/999999')->assertStatus(404);
        $this->withToken($token)->getJson('/api/v1/admin/user/all?q=changed&size=500')->assertOk()
            ->assertJsonPath('data.size', 100)->assertJsonPath('data.total', 1);
    }

    public function test_lock_enable_unlock_and_force_password_reset(): void
    {
        $token = $this->tokenFor($this->icto());
        $target = User::factory()->student()->create(['account_locked' => true, 'failed_attempts' => 5]);

        $this->withToken($token)->putJson("/api/v1/admin/user/{$target->id}/lock")->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertFalse($target->fresh()->status_flag);
        $this->withToken($token)->putJson("/api/v1/admin/user/{$target->id}/enable")->assertOk()->assertJsonPath('data.enabled', true);
        $this->withToken($token)->putJson("/api/v1/admin/user/{$target->id}/unlock")->assertOk()->assertJsonPath('data.locked', false);
        $this->withToken($token)->putJson("/api/v1/admin/user/{$target->id}/force-password-reset")->assertOk();
        $this->assertTrue($target->fresh()->must_change_password);
    }

    public function test_standard_roles_cannot_be_renamed(): void
    {
        $token = $this->tokenFor($this->icto());
        $role = Role::where('role_name', 'ADMIN')->first();

        $this->withToken($token)->putJson('/api/v1/admin/role/'.$role->id, ['roleName' => 'BOSS', 'roleDescription' => 'x'])
            ->assertStatus(400)->assertJson(['errorCode' => 'ROLE_PROTECTED', 'message' => "Standard roles can't be renamed."]);
        $this->assertSame('ADMIN', $role->fresh()->role_name);

        $this->withToken($token)->putJson('/api/v1/admin/role/'.$role->id, ['roleName' => 'ADMIN', 'roleDescription' => 'Changed'])
            ->assertOk();
        $this->assertSame('Changed', $role->fresh()->role_description);
    }

    public function test_renaming_a_custom_role_updates_app_rules(): void
    {
        $token = $this->tokenFor($this->icto());

        $created = $this->withToken($token)->postJson('/api/v1/admin/role', ['roleName' => 'librarian', 'roleDescription' => 'Books'])
            ->assertOk()->assertJsonPath('data.roleName', 'LIBRARIAN');
        $this->withToken($token)->postJson('/api/v1/admin/role', ['roleName' => 'LIBRARIAN'])->assertStatus(422)->assertJsonStructure(['data' => ['roleName']]);
        $this->withToken($token)->postJson('/api/v1/admin/role', ['roleName' => 'bad name!'])->assertStatus(422);

        $app = $this->registerApp(['allowed_roles' => ['LIBRARIAN', 'ADMIN']]);
        $other = $this->registerApp(['allowed_roles' => ['ADMIN']]);

        $this->withToken($token)->putJson('/api/v1/admin/role/'.$created->json('data.id'), ['roleName' => 'Archivist'])
            ->assertOk()->assertJsonPath('data.roleName', 'ARCHIVIST');

        $this->assertSame(['ARCHIVIST', 'ADMIN'], $app['client']->fresh()->allowed_roles);
        $this->assertSame(['ADMIN'], $other['client']->fresh()->allowed_roles);
        $this->withToken($token)->getJson('/api/v1/admin/role/paginated')->assertOk()->assertJsonStructure(['data' => ['items']]);
        $this->withToken($token)->getJson('/api/v1/admin/role/all')->assertOk();
        $this->withToken($token)->putJson('/api/v1/admin/role/99999', ['roleName' => 'X'])->assertStatus(404);
    }

    public function test_logs_are_listed_newest_first(): void
    {
        $icto = $this->icto();
        $token = $this->tokenFor($icto);
        Activity::log($icto, 'First thing', 'READ', 'Test');
        Activity::log($icto, 'Second thing', 'READ', 'Test');

        $items = $this->withToken($token)->getJson('/api/v1/admin/logs')->assertOk()->json('data.items');
        $this->assertSame('Second thing', $items[0]['activity']);
    }
    public function test_renaming_a_role_leaves_similar_names_in_the_same_list_alone(): void
    {
        $token = $this->tokenFor($this->icto());
        $hr = Role::create(['role_name' => 'HR']);
        Role::create(['role_name' => 'HR_STAFF']);
        $app = $this->registerApp(['allowed_roles' => ['HR', 'HR_STAFF']]);

        $this->withToken($token)->putJson('/api/v1/admin/role/'.$hr->id, ['roleName' => 'PEOPLE'])->assertOk();

        $this->assertSame(['PEOPLE', 'HR_STAFF'], $app['client']->fresh()->allowed_roles);
    }

    public function test_a_lowercase_rename_of_a_standard_role_is_refused(): void
    {
        $token = $this->tokenFor($this->icto());
        $role = Role::where('role_name', 'STUDENT')->first();

        $this->withToken($token)->putJson('/api/v1/admin/role/'.$role->id, ['roleName' => 'pupil'])
            ->assertStatus(400)->assertJson(['errorCode' => 'ROLE_PROTECTED']);
        $this->assertSame('STUDENT', $role->fresh()->role_name);
    }

    public function test_admin_level_cannot_update_enable_unlock_or_force_reset_a_protected_account(): void
    {
        $icto = $this->icto();
        $icto->update(['account_locked' => true, 'failed_attempts' => 5, 'status_flag' => false]);
        $before = $icto->fresh()->only(['first_name', 'status_flag', 'account_locked', 'must_change_password']);
        $token = $this->tokenFor($this->adminLevel());
        $url = '/api/v1/admin/user/'.$icto->id;

        $this->withToken($token)->putJson($url, ['userType' => 'Admin', 'username' => $icto->username, 'email' => 'x@bulsu.test', 'firstName' => 'Hacked'])->assertStatus(403);
        foreach (['/enable', '/unlock', '/force-password-reset'] as $suffix) {
            $this->withToken($token)->putJson($url.$suffix)->assertStatus(403)->assertJson(['errorCode' => 'FORBIDDEN']);
        }
        $this->assertSame($before, $icto->fresh()->only(['first_name', 'status_flag', 'account_locked', 'must_change_password']));
    }

    public function test_an_unknown_user_type_id_is_reported_on_that_field(): void
    {
        $token = $this->tokenFor($this->icto());

        $response = $this->withToken($token)->postJson('/api/v1/admin/user', ['userTypeId' => 99999, 'email' => 'a@bulsu.test'])->assertStatus(422);
        $this->assertArrayHasKey('userTypeId', $response->json('data'));
        $this->assertArrayNotHasKey('userType', $response->json('data'));
    }

    public function test_logs_still_show_the_username_of_a_deleted_administrator(): void
    {
        $actor = $this->icto();
        $token = $this->tokenFor($this->icto());
        Activity::log($actor, 'Did a thing', 'READ', 'Test');
        $actor->delete();

        $items = $this->withToken($token)->getJson('/api/v1/admin/logs')->assertOk()->json('data.items');
        $this->assertSame($actor->username, $items[0]['username']);
    }

    public function test_a_malformed_client_id_is_not_looked_up(): void
    {
        $token = $this->tokenFor($this->icto());
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'oauth_clients')) {
                $queries++;
            }
        });

        $this->withToken($token)->getJson('/api/v1/client/%00abc')
            ->assertStatus(404)->assertJson(['success' => false, 'errorCode' => 'NOT_FOUND']);

        $this->assertSame(0, $queries);
    }
}
