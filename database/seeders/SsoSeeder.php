<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Database\Seeder;

class SsoSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([UserType::ADMIN, UserType::EMPLOYEE, UserType::STUDENT] as $name) {
            UserType::firstOrCreate(['user_type_name' => $name]);
        }

        foreach (Role::STANDARD as $name) {
            Role::firstOrCreate(['role_name' => $name]);
        }

        if (! User::findByUsername('admin')) {
            $admin = User::create([
                'username' => 'admin',
                'email' => 'admin@example.com',
                'password' => 'password123',
                'user_type_id' => UserType::where('user_type_name', UserType::ADMIN)->value('id'),
                'must_change_password' => true,
            ]);
            $admin->roles()->attach(Role::where('role_name', 'ICTO_ADMIN')->value('id'));
        }
    }
}
