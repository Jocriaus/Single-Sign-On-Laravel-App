<?php

namespace App\Sso\OAuth;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Laravel\Passport\Passport;
use RuntimeException;
use UnexpectedValueException;

/** The RSA key pair that signs every token this server issues; the same pair Passport uses. */
class SigningKey
{
    private ?array $parts = null;

    /** RFC 7638 SHA-256 thumbprint of the public key, base64url. */
    public function kid(): string
    {
        $parts = $this->parts();

        return self::b64url(hash('sha256', '{"e":"'.$parts['e'].'","kty":"RSA","n":"'.$parts['n'].'"}', true));
    }

    /** @return array{kty: string, alg: string, use: string, kid: string, n: string, e: string} */
    public function jwk(): array
    {
        $parts = $this->parts();

        return [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $this->kid(),
            'n' => $parts['n'],
            'e' => $parts['e'],
        ];
    }

    public function sign(array $claims, array $header = []): string
    {
        return JWT::encode($claims, $this->read('private'), 'RS256', $this->kid(), $header);
    }

    /** Verify signature and algorithm; also expiry unless $allowExpired. */
    public function decode(string $jwt, bool $allowExpired = false): array
    {
        $key = new Key($this->read('public'), 'RS256');

        try {
            $payload = JWT::decode($jwt, $key);
        } catch (ExpiredException $e) {
            if (! $allowExpired) {
                throw $e;
            }
            $payload = $e->getPayload();
        }

        $claims = json_decode(json_encode($payload), true);
        if (! is_array($claims) || ! isset($claims['exp'])) {
            throw new UnexpectedValueException('Token has no exp claim.');
        }

        return $claims;
    }

    private function parts(): array
    {
        return $this->parts ??= $this->derive();
    }

    private function derive(): array
    {
        $resource = openssl_pkey_get_public($this->read('public'));
        $rsa = $resource ? (openssl_pkey_get_details($resource)['rsa'] ?? null) : null;
        if (! $rsa) {
            throw new RuntimeException('The signing public key could not be read.');
        }

        return ['n' => self::b64url($rsa['n']), 'e' => self::b64url($rsa['e'])];
    }

    /** Same lookup as Passport: configured key, else the key file. */
    private function read(string $type): string
    {
        $key = str_replace('\n', "\n", config("passport.{$type}_key") ?? '');
        if ($key !== '') {
            return $key;
        }

        $path = Passport::keyPath("oauth-{$type}.key");
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false || $contents === '') {
            throw new RuntimeException("No signing {$type} key. Run php artisan sso:install.");
        }

        return $contents;
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
