<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'username' => 'user'.fake()->unique()->numerify('######'),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'correct-horse-battery',
            'user_type_id' => fn () => $this->typeId(UserType::STUDENT),
            'status_flag' => true,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
        ];
    }

    private function typeId(string $name): int
    {
        return UserType::firstOrCreate(['user_type_name' => $name])->id;
    }

    public function student(): static
    {
        return $this->state(function () {
            $no = fake()->unique()->numerify('2026-######');

            return ['user_type_id' => $this->typeId(UserType::STUDENT), 'student_no' => $no, 'username' => $no];
        });
    }

    public function employee(): static
    {
        return $this->state(function () {
            $no = fake()->unique()->numerify('E-######');

            return ['user_type_id' => $this->typeId(UserType::EMPLOYEE), 'employee_no' => $no, 'username' => $no];
        });
    }

    public function adminType(): static
    {
        return $this->state(fn () => [
            'user_type_id' => $this->typeId(UserType::ADMIN),
            'student_no' => null,
            'employee_no' => null,
        ]);
    }

    public function withRole(string $name): static
    {
        return $this->afterCreating(function (User $user) use ($name) {
            $role = Role::firstOrCreate(['role_name' => strtoupper($name)]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        });
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status_flag' => false]);
    }
}
