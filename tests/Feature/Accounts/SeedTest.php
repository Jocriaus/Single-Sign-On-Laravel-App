<?php

namespace Tests\Feature\Accounts;

use App\Models\Role;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeedTest extends TestCase
{
    public function test_seed_creates_types_roles_and_an_admin_who_must_change_password(): void
    {
        $this->seed();
        $this->assertSame(['Admin', 'Employee', 'Student'], UserType::orderBy('id')->pluck('user_type_name')->all());
        $this->assertEqualsCanonicalizing(Role::STANDARD, Role::pluck('role_name')->all());
        $admin = User::findByUsername('admin');
        $this->assertTrue(Hash::check('password123', $admin->password));
        $this->assertTrue($admin->must_change_password);
        $this->assertSame(['ICTO_ADMIN'], $admin->roleNames());
        $this->assertSame('Admin', $admin->userType->user_type_name);
        $this->assertSame('admin@example.com', $admin->email);
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        $this->seed();
        $this->seed();
        $this->assertSame(1, User::count());
        $this->assertSame(4, Role::count());
    }

    public function test_usernames_are_found_whatever_the_letter_case_or_spaces(): void
    {
        $this->seed();
        $this->assertNotNull(User::findByUsername('  ADMIN '));
    }

    public function test_soft_deleted_users_are_not_found(): void
    {
        $this->seed();
        User::findByUsername('admin')->delete();
        $this->assertNull(User::findByUsername('admin'));
    }

    public function test_duplicate_usernames_differing_by_case_are_rejected(): void
    {
        $this->seed();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        User::factory()->adminType()->create(['username' => ' ADMIN ']);
    }

    public function test_factory_states_work_without_seeding(): void
    {
        $s = User::factory()->student()->create();
        $this->assertSame($s->student_no, $s->username);
        $this->assertSame('Student', $s->userType->user_type_name);
        $e = User::factory()->employee()->withRole('ADMIN')->create();
        $this->assertSame($e->employee_no, $e->username);
        $this->assertTrue($e->hasRole('admin'));
        $this->assertTrue($e->hasAssignedRole());
        $this->assertNull(User::factory()->adminType()->create()->student_no);
        $this->assertNull($e->getRememberTokenName());
    }
}
