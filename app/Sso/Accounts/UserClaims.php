<?php

namespace App\Sso\Accounts;

use App\Models\User;

/** What tokens and user info say about a user. */
class UserClaims
{
    /** @return list<string> */
    public static function roles(User $user): array
    {
        return $user->roleNames();
    }

    public static function displayName(User $user): string
    {
        $name = trim(trim((string) $user->first_name).' '.trim((string) $user->last_name));

        return $name !== '' ? $name : (string) $user->username;
    }

    public static function userType(User $user): ?string
    {
        return $user->userType?->user_type_name;
    }

    public static function profile(User $user): array
    {
        $profile = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'middle_name' => $user->middle_name,
            'middle_initial' => $user->middle_initial,
        ];
        if ($user->student_no !== null) {
            $profile['studentNo'] = $user->student_no;
        }
        if ($user->employee_no !== null) {
            $profile['employeeNo'] = $user->employee_no;
        }

        return $profile;
    }

    /** Why the user may not be given tokens, or null when they may. */
    public static function inactiveReason(User $user): ?string
    {
        return match (true) {
            ! $user->status_flag => 'This account is disabled.',
            Lockout::isLocked($user) => 'This account is locked.',
            (bool) $user->must_change_password => 'The user must change their password before signing in to apps.',
            default => null,
        };
    }
}
