<?php

namespace App\Sso\Accounts;

/**
 * Rules for new passwords, following NIST SP 800-63B: a minimum length and a blocklist of common or
 * context-specific passwords, but no "must contain a symbol" rules.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    public const MAX_LENGTH = 128;

    /** Very common passwords and ones specific to BulSU. Compared case-insensitively. */
    private const COMMON = [
        'password', 'password1', 'password12', 'password123', 'password1234', 'passw0rd123',
        '1234567890', '0123456789', '12345678910', '123456789a', 'qwertyuiop', 'qwerty1234', 'qwerty12345',
        '1q2w3e4r5t', 'iloveyou123', 'abcdefghij', 'abcd123456', 'welcome123', 'letmein123', 'admin12345',
        'administrator', 'changeme123', 'bulsu12345', 'bulsu123456', 'bulacanstate', 'bulacanstateuniversity',
        'malolos123', 'student123', 'student1234', 'employee123',
    ];

    /** @return list<string> Human-readable problems, or an empty list if the password is acceptable. */
    public function problems(?string $password, ?string $username): array
    {
        if ($password === null || mb_strlen($password) < self::MIN_LENGTH) {
            return ['Use at least '.self::MIN_LENGTH.' characters.'];
        }
        if (mb_strlen($password) > self::MAX_LENGTH) {
            return ['Use at most '.self::MAX_LENGTH.' characters.'];
        }

        $problems = [];
        $lower = mb_strtolower($password);
        if ($username !== null && mb_strlen($username) >= 3 && str_contains($lower, mb_strtolower($username))) {
            $problems[] = "Don't use your username in your password.";
        }
        if (in_array($lower, self::COMMON, true) || count(array_unique(mb_str_split($lower))) <= 2) {
            $problems[] = 'This password is too common. Choose another one.';
        }

        return $problems;
    }
}
