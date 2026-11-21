<?php

namespace Tests\Unit;

use App\Sso\Accounts\PasswordPolicy;
use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function test_policy_rules(): void
    {
        $p = new PasswordPolicy;
        $this->assertSame(['Use at least 10 characters.'], $p->problems('short', 'juan'));
        $this->assertSame(['Use at least 10 characters.'], $p->problems(null, 'juan'));
        $this->assertSame(['Use at most 128 characters.'], $p->problems(str_repeat('ab', 65), 'juan'));
        $this->assertSame(["Don't use your username in your password."], $p->problems('xx-2021JUAN-xx', '2021juan'));
        $this->assertSame(['This password is too common. Choose another one.'], $p->problems('BulSU12345', 'juan'));
        $this->assertSame(['This password is too common. Choose another one.'], $p->problems('aaaaaaaaaaaa', 'juan'));
        $this->assertSame([], $p->problems('correct-horse-battery', 'juan'));
        $this->assertSame([], $p->problems('correct-horse-battery', 'ab'));
        $this->assertSame(10, PasswordPolicy::MIN_LENGTH);
        $this->assertSame(128, PasswordPolicy::MAX_LENGTH);
    }
}
