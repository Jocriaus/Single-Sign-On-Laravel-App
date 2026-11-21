<?php

namespace App\Sso\OAuth;

final class Scopes
{
    public const ALLOWED = ['openid', 'profile', 'email', 'sso.admin'];

    public const DEFAULT = ['openid', 'profile', 'email'];

    public const ADMIN = 'sso.admin';
}
