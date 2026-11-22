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

    /** Passport only looks up UUID ids; the admin console client has a readable id. */
    protected function isValidUniqueId($value): bool
    {
        return self::validId($value);
    }

    /** The shapes a client id can have: a UUID, or a readable id such as sso-admin-console. */
    public static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[A-Za-z0-9-]{1,100}\z/', $id) === 1;
    }

    /** Find a client by an id that came from outside; anything malformed is not looked up at all. */
    public static function findById(mixed $id): ?static
    {
        return self::validId($id) ? static::find($id) : null;
    }
}
