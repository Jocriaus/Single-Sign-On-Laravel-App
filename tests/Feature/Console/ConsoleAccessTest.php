<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Sso\Activity;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class ConsoleAccessTest extends TestCase
{
    use OAuthFlow;

    public function test_guests_go_to_login_and_ordinary_users_get_403(): void
    {
        $this->get('/admin')->assertRedirect('/login');

        $this->signInToSso(User::factory()->student()->create())
            ->get('/admin')
            ->assertForbidden()
            ->assertSee("You don't have access to this page.", false);
    }

    public function test_the_403_page_shows_no_console_navigation(): void
    {
        $this->signInToSso(User::factory()->student()->create(['username' => 'plain-student']))
            ->get('/admin')
            ->assertForbidden()
            ->assertSee('plain-student')
            ->assertSee('Sign out')
            ->assertDontSee('/admin/apps', false)
            ->assertDontSee('/admin/users', false);
    }

    public function test_non_icto_users_cannot_post_to_the_apps_routes(): void
    {
        $app = $this->registerApp(['name' => 'Untouched']);
        $id = $app['client']->id;
        $hash = $app['client']->getRawOriginal('secret');

        foreach ([User::factory()->employee()->withRole('ADMIN')->create(), User::factory()->student()->create()] as $user) {
            $this->signInToSso($user);
            $this->post('/admin/apps', ['name' => 'Evil', 'redirect_uris' => 'https://e.test/cb'])->assertForbidden();
            $this->post("/admin/apps/{$id}", ['name' => 'Evil', 'redirect_uris' => 'https://e.test/cb'])->assertForbidden();
            $this->post("/admin/apps/{$id}/secret", ['confirm' => '1'])->assertForbidden();
            $this->post("/admin/apps/{$id}/delete", ['confirm' => '1'])->assertForbidden();
        }

        $fresh = $app['client']->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Untouched', $fresh->name);
        $this->assertSame($hash, $fresh->getRawOriginal('secret'));
        $this->assertSame(1, \App\Models\Client::count());
    }

    public function test_icto_admin_and_admin_can_open_the_console(): void
    {
        $this->signInToSso(User::factory()->employee()->withRole('ICTO_ADMIN')->create())
            ->get('/admin')->assertOk()->assertSee('Overview');

        $this->signInToSso(User::factory()->employee()->withRole('ADMIN')->create())
            ->get('/admin')->assertOk();
    }

    public function test_admin_sees_account_counts_but_no_apps_section(): void
    {
        User::factory()->student()->disabled()->create();
        $admin = User::factory()->employee()->withRole('ADMIN')->create(['username' => 'the-admin']);
        Activity::log($admin, 'Did a recent thing', Activity::UPDATE, 'Users');

        $this->signInToSso($admin)->get('/admin')
            ->assertOk()
            ->assertSee('Disabled accounts')
            ->assertSee('Did a recent thing')
            ->assertSee('the-admin')
            ->assertDontSee('/admin/apps', false);

        $this->get('/admin/apps')->assertForbidden();
    }

    public function test_icto_admin_sees_the_apps_link_and_count(): void
    {
        $this->registerApp();
        $this->signInToSso(User::factory()->employee()->withRole('ICTO_ADMIN')->create())
            ->get('/admin')->assertOk()->assertSee('/admin/apps', false);
    }
}
