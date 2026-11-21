<?php

namespace Tests\Feature\Sso;

use App\Sso\OAuth\SigningKey;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Facades\File;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    public function test_discovery_document_lists_this_servers_endpoints(): void
    {
        $iss = config('sso.issuer');
        $response = $this->getJson('/.well-known/openid-configuration')->assertOk()->assertJson([
            'issuer' => $iss,
            'authorization_endpoint' => "$iss/oauth2/authorize",
            'token_endpoint' => "$iss/oauth2/token",
            'userinfo_endpoint' => "$iss/userinfo",
            'jwks_uri' => "$iss/oauth2/jwks",
            'end_session_endpoint' => "$iss/connect/logout",
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email', 'sso.admin'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'backchannel_logout_supported' => true,
            'backchannel_logout_session_supported' => true,
        ]);
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=3600', $response->headers->get('Cache-Control'));
    }

    public function test_jwks_publishes_the_key_that_signs_tokens(): void
    {
        $key = app(SigningKey::class);
        $jwt = $key->sign(['iss' => 'x', 'exp' => time() + 60]);
        $response = $this->getJson('/oauth2/jwks')->assertOk();
        $jwks = $response->json();
        $this->assertSame($key->kid(), $jwks['keys'][0]['kid']);
        $this->assertSame(['kty', 'alg', 'use', 'kid', 'n', 'e'], array_keys($jwks['keys'][0]));
        $this->assertArrayNotHasKey('d', $jwks['keys'][0]);
        $this->assertStringContainsString('max-age=3600', $response->headers->get('Cache-Control'));
        $decoded = JWT::decode($jwt, JWK::parseKeySet($jwks, 'RS256'));
        $this->assertSame('x', $decoded->iss);
    }

    public function test_kid_is_the_rfc7638_thumbprint(): void
    {
        $key = app(SigningKey::class);
        $jwk = $key->jwk();
        $json = '{"e":"'.$jwk['e'].'","kty":"RSA","n":"'.$jwk['n'].'"}';
        $expected = rtrim(strtr(base64_encode(hash('sha256', $json, true)), '+/', '-_'), '=');
        $this->assertSame($expected, $key->kid());
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertSame('sig', $jwk['use']);
    }

    public function test_sign_sets_kid_and_merges_header_and_decode_round_trips(): void
    {
        $key = app(SigningKey::class);
        $jwt = $key->sign(['sub' => '1', 'exp' => time() + 60], ['typ' => 'logout+jwt']);
        $header = json_decode(base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/')), true);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame($key->kid(), $header['kid']);
        $this->assertSame('logout+jwt', $header['typ']);
        $this->assertSame('1', $key->decode($jwt)['sub']);
        $this->assertSame($key, app(SigningKey::class));
    }

    public function test_decode_rejects_expired_tokens_unless_allowed(): void
    {
        $key = app(SigningKey::class);
        $jwt = $key->sign(['sub' => '1', 'exp' => time() - 100]);
        try {
            $key->decode($jwt);
            $this->fail('expired token accepted');
        } catch (ExpiredException) {
            $this->assertTrue(true);
        }
        $this->assertSame('1', $key->decode($jwt, true)['sub']);
    }

    public function test_decode_rejects_a_bad_signature_even_when_expired_is_allowed(): void
    {
        $key = app(SigningKey::class);
        $jwt = $key->sign(['sub' => '1', 'exp' => time() - 100]);
        [$h, $p, $s] = explode('.', $jwt);
        $bad = $h.'.'.$p.'.'.strrev($s);
        $this->expectException(SignatureInvalidException::class);
        $key->decode($bad, true);
    }

    public function test_both_public_endpoints_set_no_cookies(): void
    {
        foreach (['/.well-known/openid-configuration', '/oauth2/jwks'] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertSame([], $response->headers->getCookies(), $path);
            $this->assertNull($response->headers->get('Set-Cookie'), $path);
        }
    }

    public function test_decode_rejects_a_token_without_exp_in_both_modes(): void
    {
        $key = app(SigningKey::class);
        $jwt = $key->sign(['sub' => '1']);
        foreach ([false, true] as $allowExpired) {
            try {
                $key->decode($jwt, $allowExpired);
                $this->fail('token without exp accepted');
            } catch (\UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_decode_returns_nested_claims_as_arrays(): void
    {
        $key = app(SigningKey::class);
        $claims = $key->decode($key->sign([
            'exp' => time() + 60,
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass],
            'nested' => ['a' => ['b' => 1]],
        ]));
        $this->assertSame([], $claims['events']['http://schemas.openid.net/event/backchannel-logout']);
        $this->assertSame(1, $claims['nested']['a']['b']);
    }

    public function test_decode_rejects_alg_none(): void
    {
        $b = fn ($a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
        $jwt = $b(['alg' => 'none', 'typ' => 'JWT']).'.'.$b(['sub' => '1', 'exp' => time() + 60]).'.';
        $this->expectException(\Throwable::class);
        app(SigningKey::class)->decode($jwt);
    }

    public function test_decode_rejects_hs256_signed_with_the_public_key(): void
    {
        $jwt = JWT::encode(['sub' => '1', 'exp' => time() + 60], config('passport.public_key'), 'HS256');
        $this->expectException(\Throwable::class);
        app(SigningKey::class)->decode($jwt);
    }

    public function test_keys_fall_back_to_the_key_files_and_install_never_overwrites(): void
    {
        $dir = storage_path('framework/testing/keys-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($dir);
        $previous = Passport::keyPath('x');
        Passport::loadKeysFrom($dir);
        config(['passport.private_key' => null, 'passport.public_key' => null]);

        try {
            $this->artisan('sso:install')->assertSuccessful();
            $this->assertFileExists("$dir/oauth-private.key");
            $this->assertFileExists("$dir/oauth-public.key");
            $before = file_get_contents("$dir/oauth-private.key");

            $this->artisan('sso:install')->expectsOutput('Signing key already exists.')->assertSuccessful();
            $this->assertSame($before, file_get_contents("$dir/oauth-private.key"));

            $key = new SigningKey;
            $this->assertSame('1', $key->decode($key->sign(['sub' => '1', 'exp' => time() + 60]))['sub']);
        } finally {
            Passport::loadKeysFrom(dirname($previous));
            File::deleteDirectory($dir);
        }
    }
}
