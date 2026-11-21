<?php

namespace App\Sso\Accounts;

use App\Models\User;

class Lockout
{
    public static function isLocked(User $user): bool
    {
        return $user->account_locked
            && ($user->locked_until === null || $user->locked_until->isFuture());
    }

    public static function recordFailure(User $user): void
    {
        $user->failed_attempts = (int) $user->failed_attempts + 1;

        if ($user->failed_attempts >= config('sso.lockout.max_attempts')) {
            $user->account_locked = true;
            $user->locked_until = now()->addMinutes(config('sso.lockout.minutes'));
        }

        $user->save();
    }

    public static function clear(User $user): void
    {
        $user->failed_attempts = 0;
        $user->account_locked = false;
        $user->locked_until = null;
        $user->save();
    }
}
