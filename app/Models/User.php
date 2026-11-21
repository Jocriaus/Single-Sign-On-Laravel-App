<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'username', 'email', 'password', 'user_type_id', 'status_flag',
        'first_name', 'middle_name', 'middle_initial', 'last_name', 'suffix',
        'student_no', 'employee_no', 'password_changed_at', 'failed_attempts',
        'account_locked', 'locked_until', 'must_change_password',
    ];

    protected $hidden = ['password'];

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            $taken = static::withTrashed()
                ->whereRaw('LOWER(username) = ?', [mb_strtolower((string) $user->username)])
                ->when($user->exists, fn ($q) => $q->where('id', '!=', $user->getKey()))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'username' => 'This sign-in number is already used by another account.',
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status_flag' => 'boolean',
            'account_locked' => 'boolean',
            'must_change_password' => 'boolean',
            'failed_attempts' => 'integer',
            'password_changed_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    public function setUsernameAttribute(?string $value): void
    {
        $this->attributes['username'] = $value === null ? null : trim($value);
    }

    /** There is no remember_token column. */
    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public static function findByUsername(string $username): ?User
    {
        return static::query()
            ->whereRaw('LOWER(username) = ?', [mb_strtolower(trim($username))])
            ->first();
    }

    public function userType(): BelongsTo
    {
        return $this->belongsTo(UserType::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        return $this->roles()->pluck('role_name')
            ->map(fn ($n) => strtoupper($n))->unique()->sort()->values()->all();
    }

    public function hasRole(string ...$names): bool
    {
        return array_intersect(array_map('strtoupper', $names), $this->roleNames()) !== [];
    }

    public function hasAssignedRole(): bool
    {
        return array_diff($this->roleNames(), Role::AUTOMATIC) !== [];
    }
}
