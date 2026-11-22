<?php

namespace Tests\Feature\Console;

use App\Models\Client;
use App\Models\Role;
use App\Models\SignIn;
use App\Models\User;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class ConsoleAppsTest extends TestCase
{
    use OAuthFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInToSso(User::factory()->employee()->withRole('ICTO_ADMIN')->create());
    }

    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'Library',
            'redirect_uris' => "https://library.test/cb\nhttps://library.test/cb2",
            'access_mode' => 'everyone',
            'require_pkce' => '1',
        ];
    }

    public function test_icto_admin_registers_an_app_and_sees_the_secret_once(): void
    {
        $response = $this->post('/admin/apps', $this->form(['may_administer' => '1']))->assertOk();
        $client = Client::firstOrFail();
        $secret = $response->viewData('secret');

        $response->assertSee($client->id)->assertSee($secret);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(['openid', 'profile', 'email', 'sso.admin'], $client->scopes);
        $this->assertSame(['https://library.test/cb', 'https://library.test/cb2'], $client->redirect_uris);
        $this->assertNull(session('secret'));

        $this->get('/admin/apps/'.$client->id)->assertOk()->assertDontSee($secret);
    }

    public function test_validation_errors_are_shown_on_the_form(): void
    {
        $this->from('/admin/apps/new')
            ->post('/admin/apps', $this->form(['name' => 'Typed name', 'redirect_uris' => 'ftp://nope']))
            ->assertRedirect('/admin/apps/new');

        $this->get('/admin/apps/new')
            ->assertSee('Must be absolute http or https addresses', false)
            ->assertSee('Typed name')
            ->assertSee('ftp://nope');
        $this->assertSame(0, Client::count());
    }

    public function test_a_failed_submit_with_a_blank_name_keeps_the_ticks(): void
    {
        $this->from('/admin/apps/new')
            ->post('/admin/apps', $this->form(['name' => '', 'may_administer' => '1', 'require_pkce' => null]))
            ->assertRedirect('/admin/apps/new');

        $html = $this->get('/admin/apps/new')->getContent();
        $this->assertMatchesRegularExpression('/name="may_administer"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="require_pkce"[^>]*checked/', $html);
    }

    public function test_editing_an_app_keeps_its_id_and_secret(): void
    {
        $app = $this->registerApp(['name' => 'Old']);
        $hash = $app['client']->getRawOriginal('secret');

        $this->post('/admin/apps/'.$app['client']->id, $this->form(['name' => 'New name']))
            ->assertRedirect('/admin/apps/'.$app['client']->id)
            ->assertSessionHas('status', 'Saved.');

        $fresh = $app['client']->fresh();
        $this->assertSame('New name', $fresh->name);
        $this->assertSame($hash, $fresh->getRawOriginal('secret'));
        $this->assertSame(['openid', 'profile', 'email'], $fresh->scopes);
        $this->assertSame(1, Client::count());
    }

    public function test_access_rule_is_saved_and_summarised(): void
    {
        foreach (['STUDENT', 'EMPLOYEE'] as $name) {
            Role::firstOrCreate(['role_name' => $name]);
        }
        $app = $this->registerApp();
        $url = '/admin/apps/'.$app['client']->id;

        $this->post($url, $this->form(['access_mode' => 'roles', 'allowed_roles' => ['STUDENT', 'EMPLOYEE']]));
        $this->get('/admin/apps')->assertSee('STUDENT, EMPLOYEE');
        $this->get($url)->assertSee('value="STUDENT" checked', false);

        $this->post($url, $this->form(['access_mode' => 'everyone', 'allowed_roles' => ['STUDENT']]));
        $this->assertNull($app['client']->fresh()->allowed_roles);
        $this->get('/admin/apps')->assertSee('Everyone')->assertDontSee('STUDENT, EMPLOYEE');
    }

    public function test_new_secret_replaces_the_old_one(): void
    {
        $app = $this->registerApp();
        $url = '/admin/apps/'.$app['client']->id.'/secret';

        $this->post($url)->assertSessionHasErrors('confirm');

        $response = $this->post($url, ['confirm' => '1'])->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $new = $response->viewData('secret');
        $response->assertSee($new);
        $this->assertNotSame($app['secret'], $new);
        $this->assertTrue(app('hash')->check($new, $app['client']->fresh()->getRawOriginal('secret')));
    }

    public function test_removing_an_app_deletes_it_and_its_sign_ins(): void
    {
        $app = $this->registerApp();
        $this->tokensFor(User::factory()->create(), $app);
        $this->assertGreaterThan(0, SignIn::count());
        $this->signInToSso(User::factory()->employee()->withRole('ICTO_ADMIN')->create());
        $url = '/admin/apps/'.$app['client']->id.'/delete';

        $this->post($url)->assertSessionHasErrors('confirm');
        $this->assertSame(1, Client::count());

        $this->post($url, ['confirm' => '1'])->assertRedirect('/admin/apps')->assertSessionHas('status', 'App removed.');
        $this->assertSame(0, Client::count());
        $this->assertSame(0, SignIn::count());
    }

    public function test_unknown_app_shows_the_console_not_found_page(): void
    {
        $this->get('/admin/apps/nope')->assertNotFound()->assertSee('Back to the overview');
        $this->post('/admin/apps/nope', $this->form())->assertNotFound();
    }

    public function test_forms_need_a_csrf_token(): void
    {
        $this->app['env'] = 'local';   // the CSRF check is skipped while running tests

        $this->post('/admin/apps', $this->form())->assertStatus(419);
        $this->assertSame(0, Client::count());
    }
}
