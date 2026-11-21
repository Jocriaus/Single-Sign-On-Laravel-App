<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as PassportClient;

class Client extends PassportClient
{
    protected $casts = [
        'grant_types' => 'array',
        'scopes' => 'array',
        'redirect_uris' => 'array',
        'post_logout_redirect_uris' => 'array',
        'allowed_roles' => 'array',
        'personal_access_client' => 'bool',
        'password_client' => 'bool',
        'revoked' => 'bool',
        'require_pkce' => 'bool',
    ];

    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return true;
    }
}
