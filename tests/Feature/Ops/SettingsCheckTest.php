<?php

namespace Tests\Feature\Ops;

use App\Console\Commands\SsoCheck;
use Tests\TestCase;

class SettingsCheckTest extends TestCase
{
    private function problems(string $issuer = 'https://sso.bulsu.edu.ph', bool $secure = true, bool $debug = false, string $secret = ''): array
    {
        return SsoCheck::problems($issuer, $secure, $debug, $secret);
    }

    public function test_a_clean_production_setup_has_no_problems(): void
    {
        $this->assertSame([], $this->problems());
        $this->assertSame([], $this->problems(secret: str_repeat('x', 24)));
    }

    public function test_issuer_must_be_https(): void
    {
        $this->assertSame(
            ['SSO_ISSUER must be the public https:// address of this server (is http://sso.bulsu.edu.ph).'],
            $this->problems('http://sso.bulsu.edu.ph'),
        );
    }

    public function test_issuer_must_not_be_localhost(): void
    {
        $message = 'SSO_ISSUER must be the public address of this server, not localhost.';
        $this->assertContains($message, $this->problems('https://localhost'));
        $this->assertContains($message, $this->problems('https://127.0.0.1:8443'));
        $this->assertContains($message, $this->problems('http://localhost:8000'));
    }

    public function test_cookie_must_be_secure(): void
    {
        $this->assertSame(
            ['SESSION_SECURE_COOKIE must be true so the SSO session cookie is only sent over HTTPS.'],
            $this->problems(secure: false),
        );
    }

    public function test_debug_must_be_off(): void
    {
        $this->assertSame(['APP_DEBUG must be false in production.'], $this->problems(debug: true));
    }

    public function test_weak_admin_client_secret_is_reported(): void
    {
        $message = 'SSO_ADMIN_CLIENT_SECRET must be a random value of at least 24 characters (or unset).';
        $this->assertSame([$message], $this->problems(secret: 'short'));
        $this->assertSame([$message], $this->problems(secret: 'Change-Me'));
        $this->assertSame([], $this->problems(secret: ''));
    }

    public function test_the_command_exits_0_outside_production(): void
    {
        config(['sso.issuer' => 'http://localhost:8000', 'app.debug' => true]);

        $this->artisan('sso:check')->expectsOutput('Not production: nothing to check.')->assertExitCode(0);
    }

    public function test_the_command_prints_problems_and_exits_1_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['sso.issuer' => 'https://sso.bulsu.edu.ph', 'app.debug' => true, 'session.secure' => true]);

        $this->artisan('sso:check')
            ->expectsOutput('APP_DEBUG must be false in production.')
            ->assertExitCode(1);
    }

    public function test_the_command_exits_0_for_a_safe_production_setup(): void
    {
        $this->app['env'] = 'production';
        config(['sso.issuer' => 'https://sso.bulsu.edu.ph', 'app.debug' => false, 'session.secure' => true, 'sso.admin_client_secret' => '']);

        $this->artisan('sso:check')->expectsOutput('Production settings look safe.')->assertExitCode(0);
    }

    public function test_production_notes_a_missing_proxy_setting_without_failing(): void
    {
        $this->app['env'] = 'production';
        config(['sso.issuer' => 'https://sso.bulsu.edu.ph', 'app.debug' => false, 'session.secure' => true, 'sso.trusted_proxies' => '']);
        $note = 'Note: SSO_TRUSTED_PROXIES is not set. If this server is behind a reverse proxy, set it, or every visitor will share one rate limit.';

        $this->artisan('sso:check')->expectsOutput($note)->expectsOutput('Production settings look safe.')->assertExitCode(0);
    }

    public function test_no_note_when_proxies_are_trusted(): void
    {
        $this->app['env'] = 'production';
        config(['sso.issuer' => 'https://sso.bulsu.edu.ph', 'app.debug' => false, 'session.secure' => true, 'sso.trusted_proxies' => '10.0.0.1']);

        $this->artisan('sso:check')->doesntExpectOutputToContain('SSO_TRUSTED_PROXIES')->assertExitCode(0);
    }
}
