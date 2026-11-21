<?php

namespace App\Sso\OAuth;

use App\Models\SignIn;

/**
 * The sign-in behind the code or token being issued in this request. Set (or cleared) before every
 * issue, so a value never leaks from an earlier request.
 */
class SignInContext
{
    private ?SignIn $signIn = null;

    private bool $refresh = false;

    public function set(?SignIn $signIn, bool $refresh = false): void
    {
        $this->signIn = $signIn;
        $this->refresh = $signIn !== null && $refresh;
    }

    public function signIn(): ?SignIn
    {
        return $this->signIn;
    }

    public function isRefresh(): bool
    {
        return $this->refresh;
    }
}
