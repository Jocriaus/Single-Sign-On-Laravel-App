<?php

namespace Tests\Feature\Sso;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    private function flagged(): User
    {
        return User::factory()->mustChangePassword()->create();
    }

    private function change(array $over = [])
    {
        return $this->post('/change-password', $over + [
            'current_password' => 'correct-horse-battery',
            'new_password' => 'a-brand-new-passphrase',
            'new_password_confirmation' => 'a-brand-new-passphrase',
        ]);
    }

    public function test_user_who_must_change_password_is_kept_on_the_change_page(): void
    {
        $this->actingAs($this->flagged())->get('/')->assertRedirect('/change-password');
    }

    public function test_allowed_pages_stay_reachable(): void
    {
        $this->actingAs($this->flagged());
        $this->get('/change-password')->assertOk();
        // These endpoints arrive in later tasks; here we only require that the user is not bounced.
        foreach (['/oauth2/jwks', '/.well-known/openid-configuration', '/actuator/health', '/up'] as $path) {
            $this->assertNotSame(
                url('/change-password'),
                $this->get($path)->headers->get('Location'),
                $path
            );
        }
        $this->post('/logout')->assertRedirect('/login?logout');
    }

    public function test_wrong_current_password_is_refused(): void
    {
        $user = $this->flagged();
        $this->actingAs($user)->from('/change-password');
        $this->change(['current_password' => 'nope'])->assertRedirect('/change-password');
        $this->get('/change-password')->assertSee('Your current password is wrong.');
        $this->assertTrue(Hash::check('correct-horse-battery', $user->fresh()->password));
    }

    public function test_new_password_must_pass_the_policy_and_match_its_confirmation(): void
    {
        $this->actingAs($this->flagged())->from('/change-password');
        $this->change(['new_password' => 'short', 'new_password_confirmation' => 'short'])
            ->assertRedirect('/change-password');
        $this->get('/change-password')->assertSee('Use at least 10 characters.');

        $this->change(['new_password_confirmation' => 'something-else-entirely'])
            ->assertRedirect('/change-password');
        $this->get('/change-password')->assertSee("The two new passwords don&#039;t match.", false);
    }

    public function test_new_password_must_differ_from_the_current_one(): void
    {
        $this->actingAs($this->flagged())->from('/change-password');
        $this->change(['new_password' => 'correct-horse-battery', 'new_password_confirmation' => 'correct-horse-battery'])
            ->assertRedirect('/change-password');
        $this->get('/change-password')->assertSee('Choose a password you haven&#039;t just used.', false);
    }

    public function test_changing_the_password_clears_the_flag_and_continues_where_the_user_was_going(): void
    {
        $user = $this->flagged();
        $this->actingAs($user)->withSession(['sso.sid' => 'abc', 'sso.username' => 'x']);
        $this->get('/')->assertRedirect('/change-password');
        $this->change()->assertRedirect('/');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('a-brand-new-passphrase', $user->password));
        $this->assertTrue(ActivityLog::where('activity_type', 'UPDATE')->where('user_id', $user->id)->exists());
        $this->assertAuthenticatedAs($user);
        $this->assertSame('abc', session('sso.sid'));
        $this->get('/')->assertOk();
    }

    public function test_intended_url_is_followed_after_the_change(): void
    {
        $this->actingAs($this->flagged())
            ->withSession(['url.intended' => url('/oauth2/authorize?x=1')]);
        $this->change()->assertRedirect(url('/oauth2/authorize?x=1'));
    }

    public function test_anyone_signed_in_may_change_their_password(): void
    {
        $this->actingAs(User::factory()->create())->get('/change-password')->assertOk();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/change-password')->assertRedirect('/login');
    }

    public function test_a_new_password_with_surrounding_spaces_is_stored_exactly_as_typed(): void
    {
        $user = $this->flagged();
        $typed = ' spaced passphrase here ';
        $this->actingAs($user);
        $this->change(['new_password' => $typed, 'new_password_confirmation' => $typed])->assertRedirect('/');
        $this->assertTrue(Hash::check($typed, $user->fresh()->password));

        $this->post('/logout');
        $this->post('/login', ['username' => $user->username, 'password' => $typed])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }
}
