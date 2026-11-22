<?php

namespace App\Sso\Accounts;

class TemporaryPassword
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    private const LENGTH = 14;

    public static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $password = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $password .= self::ALPHABET[random_int(0, $last)];
        }

        return $password;
    }
}
