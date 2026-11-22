<?php

namespace Tests\Support;

use App\Models\Client;
use App\Models\User;
use App\Sso\Apps\ClientRegistrar;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Plays the browser and the app in the authorization code flow. */
trait OAuthFlow
{
    /** @return array{client: Client, secret: string} */
    protected function registerApp(array $data = []): array
    {
        return app(ClientRegistrar::class)->register($data + [
            'name' => 'Test App',
            'redirect_uris' => 'https://app.test/cb',
        ]);
    }

    /** The state the login page leaves behind, without typing a password. */
    protected function signInToSso(User $user): static
    {
        return $this->actingAs($user)->withSession([
            'sso.sid' => Str::random(43),
            'sso.user_id' => $user->getKey(),
            'sso.username' => $user->username,
            'sso.auth_time' => time(),
        ]);
    }

    /** Pass a parameter as null to leave it out. */
    protected function authorize(Client $client, array $params = []): TestResponse
    {
        $query = array_filter($params + [
            'response_type' => 'code',
            'client_id' => $client->id,
            'redirect_uri' => $client->redirect_uris[0],
            'scope' => 'openid profile email',
            'code_challenge_method' => 'S256',
        ], fn ($value) => $value !== null);

        return $this->get('/oauth2/authorize?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    protected function exchange(Client $client, string $secret, string $code, string $verifier, string $method = 'post'): TestResponse
    {
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $client->redirect_uris[0],
            'code_verifier' => $verifier,
        ];

        if ($method === 'basic') {
            return $this->post('/oauth2/token', $body, [
                'Authorization' => 'Basic '.base64_encode($client->id.':'.$secret),
            ]);
        }

        return $this->post('/oauth2/token', $body + ['client_id' => $client->id, 'client_secret' => $secret]);
    }

    /** @return array<string, mixed> the token response plus 'client' and 'secret' */
    protected function tokensFor(User $user, ?array $app = null, string $scope = 'openid profile email'): array
    {
        $app ??= $this->registerApp();
        $this->signInToSso($user);
        $verifier = Str::random(64);

        $code = $this->codeFrom($this->authorize($app['client'], [
            'scope' => $scope,
            'state' => 'state-1',
            'code_challenge' => $this->challenge($verifier),
        ]));

        return $this->exchange($app['client'], $app['secret'], $code, $verifier)->assertOk()->json() + $app;
    }

    protected function refresh(array $app, string $refreshToken): TestResponse
    {
        return $this->post('/oauth2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $app['client']->id,
            'client_secret' => $app['secret'],
        ]);
    }

    /** Query parameters of the redirect back to the app. */
    protected function redirectQuery(TestResponse $response): array
    {
        $response->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    protected function codeFrom(TestResponse $response): string
    {
        $query = $this->redirectQuery($response);
        $this->assertArrayHasKey('code', $query, 'No code in '.$response->headers->get('Location'));

        return $query['code'];
    }

    protected function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** Payload of a JWT, not verified. */
    protected function claims(string $jwt): array
    {
        return $this->jwtPart($jwt, 1);
    }

    /** Header of a JWT, not verified. */
    protected function header(string $jwt): array
    {
        return $this->jwtPart($jwt, 0);
    }

    private function jwtPart(string $jwt, int $index): array
    {
        $part = explode('.', $jwt)[$index] ?? '';

        return json_decode(base64_decode(strtr($part, '-_', '+/')), true) ?? [];
    }
}
