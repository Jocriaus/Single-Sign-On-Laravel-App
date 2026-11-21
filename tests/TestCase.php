<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use phpseclib4\Crypt\RSA;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** @var array{private: string, public: string}|null */
    private static ?array $passportKeys = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$passportKeys === null) {
            $key = RSA::createKey(2048);
            self::$passportKeys = [
                'private' => (string) $key,
                'public' => (string) $key->getPublicKey(),
            ];
        }

        config([
            'passport.private_key' => self::$passportKeys['private'],
            'passport.public_key' => self::$passportKeys['public'],
        ]);
    }
}
