<?php

namespace Tests\Feature\Sso;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class HostileClientIdTest extends TestCase
{
    use OAuthFlow;

    /** @return array<string, array{string}> */
    public static function badIds(): array
    {
        return [
            'unknown' => ['no-such-app'],
            'long' => [str_repeat('a', 300)],
            'nul byte' => ["abc\0def"],
            'invalid utf-8' => ["\xC3\x28"],
        ];
    }

    private function clientQueries(callable $run): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (str_contains($query->sql, 'oauth_clients')) {
                $count++;
            }
        });
        $run();

        return $count;
    }

    /** @dataProvider badIds */
    #[\PHPUnit\Framework\Attributes\DataProvider('badIds')]
    public function test_authorize_refuses_a_bad_client_id_with_the_error_page(string $id): void
    {
        $this->signInToSso(User::factory()->student()->create());

        $queries = $this->clientQueries(function () use ($id) {
            $this->get('/oauth2/authorize?'.http_build_query([
                'response_type' => 'code', 'client_id' => $id, 'redirect_uri' => 'https://app.test/cb',
                'scope' => 'openid', 'code_challenge' => 'x', 'code_challenge_method' => 'S256',
            ]))->assertStatus(400);
        });

        if ($id !== 'no-such-app') {
            $this->assertSame(0, $queries);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badIds')]
    public function test_token_endpoint_answers_invalid_client_for_a_bad_client_id(string $id): void
    {
        $queries = $this->clientQueries(function () use ($id) {
            $this->post('/oauth2/token', [
                'grant_type' => 'authorization_code', 'code' => 'x', 'redirect_uri' => 'https://app.test/cb',
                'code_verifier' => str_repeat('a', 50), 'client_id' => $id, 'client_secret' => 'x',
            ])->assertStatus(401)->assertJsonPath('error', 'invalid_client');
        });

        if ($id !== 'no-such-app') {
            $this->assertSame(0, $queries);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badIds')]
    public function test_login_page_ignores_a_bad_client_id_in_the_intended_url(string $id): void
    {
        $url = 'http://localhost/oauth2/authorize?client_id='.rawurlencode($id);

        $queries = $this->clientQueries(function () use ($url) {
            $this->withSession(['url.intended' => $url])->get('/login')->assertOk();
        });

        if ($id !== 'no-such-app') {
            $this->assertSame(0, $queries);
        }
    }
}
