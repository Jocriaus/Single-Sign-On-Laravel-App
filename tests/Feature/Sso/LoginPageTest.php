<?php

namespace Tests\Feature\Sso;

use App\Models\ActivityLog;
use App\Models\User;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    private function admin(): User
    {
        return User::findByUsername('admin');
    }

    private function login(string $username, string $password)
    {
        return $this->post('/login', ['username' => $username, 'password' => $password]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_correct_password_signs_in_and_goes_home(): void
    {
        $this->admin()->update(['failed_attempts' => 3]);
        $this->login('admin', 'password123')->assertRedirect('/');
        $this->assertAuthenticatedAs($this->admin());
        $this->assertSame(43, strlen(session('sso.sid')));
        $this->assertSame($this->admin()->id, session('sso.user_id'));
        $this->assertSame('admin', session('sso.username'));
        $this->assertNotNull(session('sso.auth_time'));
        $this->assertSame(0, $this->admin()->failed_attempts);
        $this->assertTrue(ActivityLog::where('activity_type', 'AUTH')->exists());
    }

    public function test_wrong_password_shows_the_generic_message(): void
    {
        $this->login('admin', 'nope')->assertRedirect('/login');
        $this->get('/login')->assertSee('Wrong username or password.');
        $this->assertSame(1, $this->admin()->failed_attempts);
    }

    public function test_unknown_username_shows_the_same_generic_message(): void
    {
        $this->login('ghost', 'nope')->assertRedirect('/login');
        $this->get('/login')->assertSee('Wrong username or password.');
        $this->assertSame(0, $this->admin()->failed_attempts);
        $this->assertGuest();
    }

    public function test_five_wrong_passwords_lock_the_account(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->login('admin', 'nope')->assertRedirect('/login');
            $this->get('/login')->assertSee('Wrong username or password.');
        }
        $admin = $this->admin();
        $this->assertTrue($admin->account_locked);
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, $admin->locked_until->timestamp, 5);
    }

    public function test_locked_account_with_the_correct_password_is_told_it_is_locked(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('admin', 'nope');
        }
        $this->login('admin', 'password123')->assertRedirect('/login');
        $this->get('/login')->assertSee('Too many failed attempts. Try again after 15 minutes.');
        $this->assertGuest();
    }

    public function test_wrong_passwords_while_locked_do_not_extend_the_lock(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('admin', 'nope');
        }
        $until = $this->admin()->locked_until->timestamp;
        $this->travel(3)->minutes();
        $this->login('admin', 'nope');
        $this->assertSame($until, $this->admin()->locked_until->timestamp);
    }

    public function test_lock_ends_by_itself_after_fifteen_minutes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('admin', 'nope');
        }
        $this->travel(16)->minutes();
        $this->login('admin', 'password123')->assertRedirect('/');
        $this->assertAuthenticatedAs($this->admin());
        $this->assertFalse($this->admin()->account_locked);
    }

    public function test_disabled_account_is_told_only_after_a_correct_password(): void
    {
        $this->admin()->update(['status_flag' => false]);
        $this->login('admin', 'nope');
        $this->get('/login')->assertSee('Wrong username or password.');
        $this->login('admin', 'password123')->assertRedirect('/login');
        $this->get('/login')->assertSee('This account is disabled. Contact the system administrator.');
        $this->assertGuest();
    }

    public function test_username_is_matched_whatever_the_case_or_spaces(): void
    {
        $this->login('Admin', 'nope');
        $this->login('ADMIN', 'nope');
        $this->assertSame(2, $this->admin()->failed_attempts);
        $this->login(' ADMIN ', 'password123')->assertRedirect('/');
        $this->assertAuthenticatedAs($this->admin());
    }

    public function test_home_redirects_guests_to_login_and_greets_signed_in_users(): void
    {
        $this->get('/')->assertRedirect('/login');
        $user = User::factory()->student()->create(['first_name' => 'Juan', 'last_name' => 'dela Cruz']);
        $this->actingAs($user)->get('/')
            ->assertOk()->assertSee("You're signed in as", false)->assertSee('Juan dela Cruz');
    }

    public function test_sign_out_ends_the_session_and_says_so(): void
    {
        $this->login('admin', 'password123');
        $this->post('/logout')->assertRedirect('/login?logout');
        $this->get('/login?logout')->assertSee("You've signed out.");
        $this->assertGuest();
    }

    public function test_after_sign_in_the_browser_returns_only_to_a_local_address(): void
    {
        $this->withSession(['url.intended' => 'https://evil.example/'])
            ->login('admin', 'password123')->assertRedirect('/');
    }

    public function test_unknown_username_still_runs_the_password_hash_check(): void
    {
        \Illuminate\Support\Facades\Hash::shouldReceive('check')->once()->andReturn(false);
        $this->login('ghost', 'nope')->assertRedirect('/login');
    }

    public function test_failed_attempts_are_incremented_atomically(): void
    {
        $stale = $this->admin();
        $this->admin()->increment('failed_attempts');
        \App\Sso\Accounts\Lockout::recordFailure($stale);
        $this->assertSame(2, $this->admin()->failed_attempts);
    }

    public static function intendedProvider(): array
    {
        return [
            'other host' => ['https://evil.example/', '/'],
            'scheme-relative' => ['//evil.example', '/'],
            'backslash' => ['/\evil.example', '/'],
            'other port' => ['http://localhost:8080/x', '/'],
            'other scheme' => ['https://localhost:8000/x', '/'],
            'control character' => ["/	/evil.example", '/'],
            'delete character' => ["/\x7F/evil.example", '/'],
            'space' => ['/ /evil.example', '/'],
            'local path' => ['/somewhere', '/somewhere'],
        ];
    }

    /** @dataProvider intendedProvider */
    public function test_intended_url_is_followed_only_when_local(string $intended, string $expected): void
    {
        $this->withSession(['url.intended' => $intended])
            ->login('admin', 'password123')->assertRedirect($expected);
    }

    public function test_an_absolute_intended_url_on_this_server_is_followed(): void
    {
        $this->withSession(['url.intended' => url('/somewhere?a=1')])
            ->login('admin', 'password123')->assertRedirect(url('/somewhere?a=1'));
    }
}
