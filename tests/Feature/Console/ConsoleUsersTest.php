<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Sso\Accounts\AutomaticRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class ConsoleUsersTest extends TestCase
{
    use OAuthFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function icto(array $attrs = []): User
    {
        $user = User::factory()->employee()->withRole('ICTO_ADMIN')->create($attrs);
        app(AutomaticRoles::class)->sync($user);

        return $user;
    }

    private function student(array $attrs = []): User
    {
        $user = User::factory()->student()->create($attrs);
        app(AutomaticRoles::class)->sync($user);

        return $user;
    }

    private function newUserForm(array $over = []): array
    {
        return $over + [
            'user_type' => 'Student', 'student_no' => '2026-100001', 'employee_no' => '', 'username' => '',
            'email' => 'new.person@bulsu.test', 'first_name' => 'New', 'last_name' => 'Person',
        ];
    }

    public function test_list_shows_name_numbers_and_roles_and_can_be_searched(): void
    {
        $this->signInToSso($this->icto());
        $this->student(['username' => 'alpha-login', 'student_no' => 'S-111', 'email' => 'alpha@x.test', 'first_name' => 'Alice', 'last_name' => 'Zeta']);
        $both = User::factory()->employee()->create(['username' => 'beta-login', 'employee_no' => 'E-222', 'email' => 'beta@x.test', 'first_name' => 'Bob', 'last_name' => 'Yarn']);
        app(AutomaticRoles::class)->sync($both);

        $this->get('/admin/users')->assertOk()
            ->assertSee('Alice Zeta')->assertSee('alpha-login')->assertSee('S-111')->assertSee('E-222')
            ->assertSee('Student')->assertSee('STUDENT')->assertSee('EMPLOYEE')->assertSee('Active');

        foreach (['alpha-login' => 'beta-login', 'S-111' => 'E-222', 'E-222' => 'S-111', 'alpha@x.test' => 'beta-login',
            'alice' => 'Bob Yarn', 'YARN' => 'Alice Zeta'] as $q => $absent) {
            $this->get('/admin/users?q='.urlencode($q))->assertOk()->assertDontSee($absent);
        }
        $this->get('/admin/users?q=E-222')->assertSee('beta-login');
        $this->get('/admin/users?q=nobody-matches')->assertOk()->assertSee('No users match');
    }

    public function test_list_pages_by_25_and_keeps_the_search(): void
    {
        $this->signInToSso($this->icto());
        User::factory()->count(26)->student()->create(['last_name' => 'Pagey']);

        $this->get('/admin/users?q=Pagey')->assertOk()->assertSee('q=Pagey&amp;page=2', false);
        $this->get('/admin/users?q=Pagey&page=2')->assertOk()->assertSee('q=Pagey&amp;page=1', false);
    }

    public function test_adding_a_user_shows_the_temporary_password_once(): void
    {
        $this->signInToSso($this->icto());

        $this->get('/admin/users/new')->assertOk()
            ->assertSee('name="student_no"', false)->assertSee('name="employee_no"', false)
            ->assertSee('Leave blank to use the student or employee number.');

        $response = $this->post('/admin/users', $this->newUserForm())->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $user = User::where('email', 'new.person@bulsu.test')->firstOrFail();
        $this->assertSame('2026-100001', $user->username);
        $password = $response->viewData('temporaryPassword');
        $response->assertSee($password);
        $this->assertNull(session('temporaryPassword'));

        $this->get('/admin/users/'.$user->id)->assertOk()->assertDontSee($password);
    }

    public function test_adding_a_user_with_bad_data_returns_to_the_form_with_errors(): void
    {
        $this->signInToSso($this->icto());
        $before = User::count();

        $this->from('/admin/users/new')->post('/admin/users', $this->newUserForm(['email' => 'nope', 'first_name' => 'Typed']))
            ->assertRedirect('/admin/users/new');
        $this->get('/admin/users/new')->assertSee('A valid email is required.')->assertSee('Typed');
        $this->assertSame($before, User::count());
    }

    public function test_unknown_user_shows_the_console_not_found_page(): void
    {
        $this->signInToSso($this->icto());
        $gone = $this->student();
        $gone->delete();

        $this->get('/admin/users/99999')->assertNotFound()->assertSee('Back to the overview');
        $this->get('/admin/users/'.$gone->id)->assertNotFound();
        $this->post('/admin/users/99999')->assertNotFound();
    }

    public function test_details_form_edits_identity_and_warns_about_the_sign_in_number(): void
    {
        $this->signInToSso($this->icto());
        $user = $this->student(['first_name' => 'Old']);

        $this->get('/admin/users/'.$user->id)->assertOk()
            ->assertSee('Changing the sign-in number signs the person out of apps.');

        $form = ['user_type' => 'Student', 'username' => $user->username, 'student_no' => $user->student_no,
            'employee_no' => '', 'email' => $user->email, 'first_name' => 'Renamed', 'last_name' => $user->last_name];
        $this->post('/admin/users/'.$user->id, $form)
            ->assertRedirect('/admin/users/'.$user->id)->assertSessionHas('status', 'Saved.');
        $this->assertSame('Renamed', $user->fresh()->first_name);

        $this->from('/admin/users/'.$user->id)->post('/admin/users/'.$user->id, ['email' => 'bad'] + $form)
            ->assertRedirect('/admin/users/'.$user->id);
        $this->get('/admin/users/'.$user->id)->assertSee('A valid email is required.');
        $this->assertSame('Renamed', $user->fresh()->first_name);
    }

    public function test_unlock_reset_require_change_disable_enable_each_work(): void
    {
        $this->signInToSso($this->icto());
        $user = $this->student(['account_locked' => true, 'failed_attempts' => 5, 'locked_until' => now()->addMinutes(10)]);
        $url = '/admin/users/'.$user->id;

        $this->get($url)->assertSee('Locked');
        $this->post($url.'/unlock')->assertRedirect($url)->assertSessionHas('status', 'Unlocked.');
        $this->assertFalse($user->fresh()->account_locked);

        $this->post($url.'/require-password-change')->assertRedirect($url)
            ->assertSessionHas('status', "They'll be asked for a new password at next sign-in.");
        $this->assertTrue($user->fresh()->must_change_password);

        $this->from($url)->post($url.'/reset-password')->assertRedirect($url)->assertSessionHasErrors('confirm');
        $response = $this->post($url.'/reset-password', ['confirm' => '1'])->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $password = $response->viewData('temporaryPassword');
        $response->assertSee($password);
        $this->assertNull(session('temporaryPassword'));
        $this->assertTrue(Hash::check($password, $user->fresh()->password));

        $this->from($url)->post($url.'/disable')->assertSessionHasErrors('confirm');
        $this->assertTrue($user->fresh()->status_flag);
        $this->post($url.'/disable', ['confirm' => '1'])->assertRedirect($url)->assertSessionHas('status', 'Disabled.');
        $this->assertFalse($user->fresh()->status_flag);
        $this->get($url)->assertSee('Disabled');

        $this->post($url.'/enable')->assertRedirect($url)->assertSessionHas('status', 'Enabled.');
        $this->assertTrue($user->fresh()->status_flag);
    }

    public function test_roles_show_student_and_employee_as_automatic(): void
    {
        $this->signInToSso($this->icto());
        $user = $this->student();
        $url = '/admin/users/'.$user->id;

        $html = $this->get($url)->assertOk()->assertSee('automatic')->getContent();
        $this->assertStringNotContainsString('value="STUDENT"', $html);
        $this->assertStringNotContainsString('value="EMPLOYEE"', $html);
        $this->assertStringContainsString('name="roles[]" value="ADMIN"', $html);

        $this->post($url.'/roles', ['roles' => ['ADMIN']])->assertRedirect($url)->assertSessionHas('status', 'Roles saved.');
        $this->assertSame(['ADMIN', 'STUDENT'], $user->fresh()->roleNames());
        $this->assertMatchesRegularExpression('/value="ADMIN"[^>]*checked/', $this->get($url)->getContent());
    }

    public function test_only_icto_admins_see_and_can_post_roles(): void
    {
        $admin = User::factory()->employee()->withRole('ADMIN')->create();
        $user = $this->student();
        $this->signInToSso($admin);

        $html = $this->get('/admin/users/'.$user->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('name="roles[]"', $html);
        $this->post('/admin/users/'.$user->id.'/roles', ['roles' => ['ADMIN']])->assertForbidden();
        $this->assertSame(['STUDENT'], $user->fresh()->roleNames());

        $select = $this->get('/admin/users/new')->assertOk()->getContent();
        $this->assertStringNotContainsString('value="Admin"', $select);
        $this->assertStringContainsString('value="Student"', $select);
    }

    public function test_admin_sees_protected_accounts_but_is_offered_no_actions(): void
    {
        $this->signInToSso(User::factory()->employee()->withRole('ADMIN')->create());
        $protected = $this->icto(['username' => 'boss']);
        $url = '/admin/users/'.$protected->id;

        $html = $this->get($url)->assertOk()->assertSee('boss')->getContent();
        $this->assertStringNotContainsString('/unlock', $html);
        $this->assertStringNotContainsString('/disable', $html);
        $this->assertStringNotContainsString('/reset-password', $html);
        $this->assertStringNotContainsString('/require-password-change', $html);
        $this->assertStringNotContainsString('name="email"', $html);

        $data = ['confirm' => '1', 'user_type' => 'Employee'];
        foreach (['', '/unlock', '/require-password-change', '/reset-password', '/disable', '/enable', '/roles'] as $action) {
            $this->post($url.$action, $data)->assertForbidden();
        }
        $this->assertTrue($protected->fresh()->status_flag);

        $this->get('/admin/users')->assertOk()->assertSee('boss');
    }

    public function test_icto_admin_cannot_disable_themselves_or_drop_their_own_role(): void
    {
        $me = $this->icto();
        $this->icto();
        $this->signInToSso($me);
        $url = '/admin/users/'.$me->id;

        $this->post($url.'/disable', ['confirm' => '1'])->assertRedirect($url);
        $this->get($url)->assertSee("You can't disable your own account.");
        $this->assertTrue($me->fresh()->status_flag);

        $this->post($url.'/roles', ['roles' => ['ADMIN']])->assertRedirect($url);
        $this->get($url)->assertSee("You can't remove your own ICTO admin role.");
        $this->assertTrue($me->fresh()->hasRole('ICTO_ADMIN'));
    }

    public function test_a_guest_is_sent_to_login_and_a_student_is_refused_on_the_user_screens(): void
    {
        $target = $this->student();

        $this->get('/admin/users')->assertRedirect('/login');

        $this->signInToSso($this->student());
        $this->get('/admin/users')->assertForbidden();
        $this->post('/admin/users/'.$target->id.'/reset-password', ['confirm' => '1'])->assertForbidden();
    }

    public function test_an_id_longer_than_eighteen_digits_is_the_not_found_page(): void
    {
        $this->signInToSso($this->icto());

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/admin/users/'.str_repeat('9', 19))->assertNotFound()->assertSee('Back to the overview');
        foreach (DB::getQueryLog() as $query) {
            $this->assertNotContains(str_repeat('9', 19), array_map('strval', $query['bindings']), 'The long id reached the database.');
        }
        DB::flushQueryLog();
        $this->post('/admin/users/'.str_repeat('1', 40).'/unlock')->assertNotFound();
    }
}
