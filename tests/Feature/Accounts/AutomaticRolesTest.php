<?php

namespace Tests\Feature\Accounts;

use App\Models\User;
use App\Sso\Accounts\AutomaticRoles;
use Tests\TestCase;

class AutomaticRolesTest extends TestCase
{
    public function test_roles_follow_the_numbers_a_person_holds(): void
    {
        $this->seed();
        $user = User::factory()->student()->create(['employee_no' => null]);
        app(AutomaticRoles::class)->sync($user);
        $this->assertSame(['STUDENT'], $user->fresh()->roleNames());
        $user->update(['employee_no' => 'E-1']);
        app(AutomaticRoles::class)->sync($user);
        $this->assertSame(['EMPLOYEE', 'STUDENT'], $user->fresh()->roleNames());
        $user->update(['student_no' => null]);
        app(AutomaticRoles::class)->sync($user);
        $this->assertSame(['EMPLOYEE'], $user->fresh()->roleNames());
    }

    public function test_assigned_roles_are_left_alone(): void
    {
        $this->seed();
        $user = User::factory()->student()->withRole('ADMIN')->create();
        app(AutomaticRoles::class)->sync($user);
        $this->assertSame(['ADMIN', 'STUDENT'], $user->fresh()->roleNames());
        $this->assertTrue($user->hasAssignedRole());
    }
}
