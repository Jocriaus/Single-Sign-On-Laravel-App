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
