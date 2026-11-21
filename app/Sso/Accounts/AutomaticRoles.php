<?php

namespace App\Sso\Accounts;

use App\Models\Role;
use App\Models\User;

class AutomaticRoles
{
    /** Grant or revoke STUDENT / EMPLOYEE from the numbers held; other roles are untouched. */
    public function sync(User $user): void
    {
        $wanted = [
            'STUDENT' => filled($user->student_no),
            'EMPLOYEE' => filled($user->employee_no),
        ];

        foreach (Role::AUTOMATIC as $name) {
            $id = Role::firstOrCreate(['role_name' => $name])->id;
            $wanted[$name]
                ? $user->roles()->syncWithoutDetaching([$id])
                : $user->roles()->detach($id);
        }
    }
}
