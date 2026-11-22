<?php

namespace App\Sso\Accounts;

use App\Models\Role;
use App\Models\User;
use App\Models\UserType;
use App\Sso\Activity;
use App\Sso\Logout\BackChannelLogout;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Every change to an account goes through here, so the account rules and the two admin levels hold everywhere. */
class AccountService
{
    private const LIMITS = [
        'student_no' => 50, 'employee_no' => 50, 'middle_initial' => 20, 'suffix' => 20,
        'first_name' => 255, 'middle_name' => 255, 'last_name' => 255, 'email' => 255, 'username' => 255,
    ];

    public function __construct(private AutomaticRoles $automaticRoles, private BackChannelLogout $logout)
    {
    }

    /** @return array{user: User, temporaryPassword: string} */
    public function create(User $actor, array $data): array
    {
        if (! $this->isActive($actor) || ! $actor->hasRole('ICTO_ADMIN', 'ADMIN')) {
            throw new AuthorizationException('Only administrators can add accounts.');
        }

        return DB::transaction(function () use ($actor, $data) {
            $fields = $this->validated($data, null, $actor);
            $temporaryPassword = TemporaryPassword::generate();

            $user = new User($fields);
            $user->password = $temporaryPassword;
            $user->must_change_password = true;
            $user->status_flag = true;
            $user->save();
            $this->automaticRoles->sync($user);

            Activity::log($actor, "Created account {$user->username}", Activity::CREATE, 'Users');

            return ['user' => $user, 'temporaryPassword' => $temporaryPassword];
        });
    }

    public function update(User $actor, User $user, array $data): User
    {
        $this->authorize($actor, $user);

        return DB::transaction(function () use ($actor, $user, $data) {
            $fields = $this->validated($data, $user, $actor);
            $renamed = mb_strtolower($fields['username']) !== mb_strtolower((string) $user->username);

            $user->fill($fields)->save();
            $this->automaticRoles->sync($user);
            // Only after the save succeeded: the notices go out after the response, whatever the transaction does.
            if ($renamed) {
                $this->logout->signOutEverywhere($user);
            }

            Activity::log($actor, "Updated account {$user->username}", Activity::UPDATE, 'Users');

            return $user;
        });
    }

    public function unlock(User $actor, User $user): void
    {
        $this->authorize($actor, $user);

        DB::transaction(function () use ($actor, $user) {
            Lockout::clear($user);
            Activity::log($actor, "Unlocked {$user->username}", Activity::UPDATE, 'Users');
        });
    }

    public function resetPassword(User $actor, User $user): string
    {
        $this->authorize($actor, $user);

        return DB::transaction(function () use ($actor, $user) {
            $temporaryPassword = TemporaryPassword::generate();
            $user->password = $temporaryPassword;
            $user->must_change_password = true;
            $user->save();
            Activity::log($actor, "Reset password for {$user->username}", Activity::UPDATE, 'Users');

            return $temporaryPassword;
        });
    }

    public function requirePasswordChange(User $actor, User $user): void
    {
        $this->authorize($actor, $user);

        DB::transaction(function () use ($actor, $user) {
            $user->update(['must_change_password' => true]);
            Activity::log($actor, "Required a password change for {$user->username}", Activity::UPDATE, 'Users');
        });
    }

    public function setEnabled(User $actor, User $user, bool $enabled): void
    {
        $this->authorize($actor, $user);

        if (! $enabled && $actor->is($user)) {
            throw ValidationException::withMessages(['status' => "You can't disable your own account."]);
        }

        DB::transaction(function () use ($actor, $user, $enabled) {
            $user->update(['status_flag' => $enabled]);
            if (! $enabled) {
                $this->assertActiveIctoAdminRemains('status');
                $this->logout->signOutEverywhere($user);
            }
            Activity::log($actor, ($enabled ? 'Enabled' : 'Disabled')." account {$user->username}", Activity::UPDATE, 'Users');
        });
    }

    /** @param  list<string>  $roleNames  assigned roles only; STUDENT and EMPLOYEE follow the numbers */
    public function setRoles(User $actor, User $user, array $roleNames): void
    {
        if (! $this->isActive($actor) || ! $actor->hasRole('ICTO_ADMIN')) {
            throw new AuthorizationException('Only ICTO admins can change roles.');
        }
        if (collect($roleNames)->contains(fn ($name) => ! is_string($name))) {
            throw ValidationException::withMessages(['roles' => 'Roles must be given as names.']);
        }

        $names = collect($roleNames)->map(fn ($name) => strtoupper(trim((string) $name)))
            ->reject(fn ($name) => $name === '' || in_array($name, Role::AUTOMATIC, true))
            ->unique()->values();

        $roles = Role::whereIn('role_name', $names)->get();
        $unknown = $names->diff($roles->pluck('role_name')->map(fn ($n) => strtoupper($n)));
        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages(['roles' => 'Unknown role: '.$unknown->implode(', ').'.']);
        }
        if ($actor->is($user) && ! $names->contains('ICTO_ADMIN')) {
            throw ValidationException::withMessages(['roles' => "You can't remove your own ICTO admin role."]);
        }

        DB::transaction(function () use ($actor, $user, $roles) {
            $automatic = Role::whereIn('role_name', Role::AUTOMATIC)->pluck('id');
            $keep = $user->roles()->whereIn('roles.id', $automatic)->pluck('roles.id');
            $user->roles()->sync($roles->pluck('id')->merge($keep)->all());
            $this->automaticRoles->sync($user);
            $this->assertActiveIctoAdminRemains('roles');

            Activity::log($actor, "Changed roles of {$user->username} to ".($roles->pluck('role_name')->implode(', ') ?: 'none'), Activity::UPDATE, 'Users');
        });
    }

    public function canManage(User $actor, User $user): bool
    {
        if (! $this->isActive($actor)) {
            return false;
        }
        if ($actor->hasRole('ICTO_ADMIN')) {
            return true;
        }

        return $actor->hasRole('ADMIN') && ! $user->hasAssignedRole();
    }

    /** A disabled or deleted administrator can no longer act, whatever roles they still hold. */
    private function isActive(User $actor): bool
    {
        $current = User::find($actor->getKey());

        return $current !== null && $current->status_flag;
    }

    /** Backstop: inside the transaction, so a change that would leave no enabled ICTO admin is rolled back. */
    private function assertActiveIctoAdminRemains(string $key): void
    {
        $remaining = User::where('status_flag', true)
            ->whereHas('roles', fn ($q) => $q->where('role_name', 'ICTO_ADMIN'))
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([$key => 'At least one active ICTO admin must remain.']);
        }
    }

    private function authorize(User $actor, User $user): void
    {
        if (! $this->canManage($actor, $user)) {
            throw new AuthorizationException('You may not manage this account.');
        }
    }

    /**
     * The columns to save, or a ValidationException keyed by the $data key.
     *
     * @return array<string, mixed>
     */
    private function validated(array $data, ?User $existing, User $actor): array
    {
        $text = fn (string $key) => ($value = trim((string) ($data[$key] ?? ''))) === '' ? null : $value;
        $errors = [];

        $type = UserType::where('user_type_name', $text('user_type'))->first();
        if (! $type) {
            throw ValidationException::withMessages(['user_type' => 'Choose Admin, Employee or Student.']);
        }
        $name = $type->user_type_name;
        if ($name === UserType::ADMIN && $existing?->user_type_id !== $type->id && ! $actor->hasRole('ICTO_ADMIN')) {
            throw ValidationException::withMessages(['user_type' => 'Only ICTO admins can create admin-type accounts.']);
        }
        $studentNo = $text('student_no');
        $employeeNo = $text('employee_no');

        if ($name === UserType::ADMIN) {
            foreach (['student_no' => $studentNo, 'employee_no' => $employeeNo] as $key => $value) {
                if ($value !== null) {
                    $errors[$key] = "Admin accounts don't carry student or employee numbers.";
                }
            }
        } else {
            if ($name === UserType::STUDENT && $studentNo === null) {
                $errors['student_no'] = 'A student needs a student number.';
            }
            if ($name === UserType::EMPLOYEE && $employeeNo === null) {
                $errors['employee_no'] = 'An employee needs an employee number.';
            }
            if ($text('first_name') === null) {
                $errors['first_name'] = 'First name is required.';
            }
            if ($text('last_name') === null) {
                $errors['last_name'] = 'Last name is required.';
            }
        }

        $email = $text('email');
        if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required.';
        } elseif ($this->taken('email', $email, $existing, lower: true)) {
            $errors['email'] = 'This email is already used by another account.';
        }

        foreach (['student_no' => [$studentNo, 'student'], 'employee_no' => [$employeeNo, 'employee']] as $key => [$value, $label]) {
            if ($value !== null && ! isset($errors[$key]) && $this->taken($key, $value, $existing)) {
                $errors[$key] = "This {$label} number is already used by another account.";
            }
        }

        foreach (self::LIMITS as $key => $max) {
            if (! isset($errors[$key]) && mb_strlen((string) $text($key)) > $max) {
                $errors[$key] = "This is too long (at most {$max} characters).";
            }
        }

        $main = $name === UserType::EMPLOYEE ? $employeeNo : ($name === UserType::STUDENT ? $studentNo : null);
        $username = $text('username') ?? $existing?->username ?? $main;
        if ($username === null && ! isset($errors['username'])) {
            $errors['username'] = 'A sign-in name is required for admin accounts.';
        }

        if ($username !== null && ! isset($errors['username'])
            && $this->taken('username', $username, $existing, lower: true)) {
            $errors['username'] = 'This sign-in number is already used by another account.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'username' => $username,
            'email' => $email,
            'user_type_id' => $type->id,
            'first_name' => $text('first_name'),
            'middle_name' => $text('middle_name'),
            'middle_initial' => $text('middle_initial'),
            'last_name' => $text('last_name'),
            'suffix' => $text('suffix'),
            'student_no' => $studentNo,
            'employee_no' => $employeeNo,
        ];
    }

    private function taken(string $column, string $value, ?User $existing, bool $lower = false): bool
    {
        return User::withTrashed()
            ->when($lower, fn ($q) => $q->whereRaw("LOWER({$column}) = ?", [mb_strtolower($value)]), fn ($q) => $q->where($column, $value))
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->getKey()))
            ->exists();
    }
}
