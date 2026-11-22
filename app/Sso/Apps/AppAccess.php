<?php

namespace App\Sso\Apps;

use App\Models\Client;
use App\Models\User;

/** Which roles may sign in to an app: no list means everyone. */
class AppAccess
{
    public static function allows(Client $client, User $user): bool
    {
        $allowed = array_map('strtoupper', $client->allowed_roles ?? []);
        if ($allowed === []) {
            return true;
        }

        return array_intersect($allowed, array_map('strtoupper', $user->roleNames())) !== [];
    }
}
