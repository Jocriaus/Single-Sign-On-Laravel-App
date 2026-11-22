<?php

namespace Tests\Feature\Ops;

use App\Sso\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    private const TEXT = 'Too many attempts from your network. Wait a minute and try again.';

    private function attempt(?string $ip = null)
    {
        $call = $ip ? $this->withServerVariables(['REMOTE_ADDR' => $ip]) : $this;

        return $call->post('/login', ['username' => 'nobody', 'password' => 'wrong-password-1']);
    }

    public function test_the_31st_login_attempt_in_a_minute_from_one_address_is_refused(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->assertNotSame(429, $this->attempt()->getStatusCode());
        }

        $response = $this->attempt()->assertStatus(429);
        $this->assertSame(self::TEXT, $response->getContent());
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
        $retry = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retry);
        $this->assertLessThanOrEqual(60, $retry);
    }

    public function test_another_address_is_not_affected(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->attempt();
        }
        $this->attempt()->assertStatus(429);

        $this->assertNotSame(429, $this->attempt('203.0.113.9')->getStatusCode());
    }

    public function test_the_limit_resets_after_a_minute(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->attempt();
        }
        $this->attempt()->assertStatus(429);

        $this->travel(61)->seconds();

        $this->assertNotSame(429, $this->attempt()->getStatusCode());
    }

    public function test_token_requests_have_their_own_limit(): void
    {
        config(['sso.rate_limit.token_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertNotSame(429, $this->post('/oauth2/token', ['grant_type' => 'authorization_code'])->getStatusCode());
        }
        $response = $this->post('/oauth2/token', ['grant_type' => 'authorization_code'])->assertStatus(429);
        $this->assertSame(self::TEXT, $response->getContent());
        $this->assertNotNull($response->headers->get('Retry-After'));

        // Separate bucket: login is untouched, and showing the login page is never limited.
        $this->assertNotSame(429, $this->attempt()->getStatusCode());
        for ($i = 0; $i < 40; $i++) {
            $this->get('/login')->assertOk();
        }
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function forwarded(string $address)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => $address])
            ->post('/login', ['username' => 'nobody', 'password' => 'wrong-password-1']);
    }

    public function test_behind_a_trusted_proxy_the_limit_is_per_forwarded_address(): void
    {
        TrustProxies::at(TrustedProxies::parse('127.0.0.1'));

        for ($i = 0; $i < 30; $i++) {
            $this->assertNotSame(429, $this->forwarded('198.51.100.7')->getStatusCode());
        }
        $this->forwarded('198.51.100.7')->assertStatus(429);

        $this->assertNotSame(429, $this->forwarded('198.51.100.8')->getStatusCode());
    }

    public function test_without_a_trusted_proxy_the_forwarded_header_cannot_dodge_the_limit(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->assertNotSame(429, $this->forwarded('198.51.100.'.$i)->getStatusCode());
        }

        $this->forwarded('198.51.100.200')->assertStatus(429);
    }

    public function test_the_trusted_proxy_setting_is_parsed(): void
    {
        $this->assertNull(TrustedProxies::parse(''));
        $this->assertNull(TrustedProxies::parse(' , '));
        $this->assertSame(['10.0.0.1', '192.168.0.0/16'], TrustedProxies::parse('10.0.0.1, 192.168.0.0/16'));
        $this->assertSame('*', TrustedProxies::parse('*'));
    }
}
