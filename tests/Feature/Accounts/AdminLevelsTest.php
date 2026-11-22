<?php

namespace Tests\Feature\Accounts;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Sso\Accounts\AccountService;
use App\Sso\Accounts\AutomaticRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminLevelsTest extends TestCase
{
    private AccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Role::firstOrCreate(['role_name' => 'HR_STAFF']);
        $this->accounts = app(AccountService::class);
    }

    private function admin(): User
    {
        return User::factory()->employee()->withRole('ADMIN')->create();
    }

    private function icto(): User
    {
        return User::findByUsername('admin');
    }

    private function details(User $user, array $overrides = []): array
    {
        return $overrides + [
            'username' => $user->username, 'email' => $user->email, 'user_type' => $user->userType->user_type_name,
            'first_name' => $user->first_name ?? 'A', 'last_name' => $user->last_name ?? 'B',
            'student_no' => $user->student_no, 'employee_no' => $user->employee_no,
        ];
    }

    /** @return array<string, callable(User, User): mixed> */
    private function actions(): array
    {
        return [
            'update' => fn (User $a, User $t) => $this->accounts->update($a, $t, $this->details($t, ['first_name' => 'Changed'])),
            'unlock' => fn (User $a, User $t) => $this->accounts->unlock($a, $t),
            'resetPassword' => fn (User $a, User $t) => $this->accounts->resetPassword($a, $t),
            'requirePasswordChange' => fn (User $a, User $t) => $this->accounts->requirePasswordChange($a, $t),
            'setEnabled' => fn (User $a, User $t) => $this->accounts->setEnabled($a, $t, false),
        ];
    }

    private function refuses(callable $action, string $class, ?string $message = null): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
            if ($message !== null) {
                $this->assertContains($message, collect($e->errors())->flatten()->all());
            }

            return;
        }
        $this->fail("Expected {$class}.");
    }

    public function test_admin_can_manage_an_ordinary_account(): void
    {
        $admin = $this->admin();
        $student = User::factory()->student()->create(['account_locked' => true, 'failed_attempts' => 5]);

        $created = $this->accounts->create($admin, [
            'email' => 'new@example.test', 'user_type' => 'Student', 'first_name' => 'N', 'last_name' => 'P', 'student_no' => '77',
        ]);
        foreach ($this->actions() as $action) {
            $action($admin, $student);
        }

        $this->assertTrue($this->accounts->canManage($admin, $student));
        $this->assertSame('Changed', $student->fresh()->first_name);
        $this->assertFalse($student->fresh()->account_locked);
        $this->assertSame(0, $student->fresh()->failed_attempts);
        $this->assertTrue($student->fresh()->must_change_password);
        $this->assertFalse($student->fresh()->status_flag);
        $this->accounts->setEnabled($admin, $student, true);
        $this->assertTrue($student->fresh()->status_flag);
        $this->assertSame(['STUDENT'], $created['user']->roleNames());
    }

    public function test_reset_password_returns_a_temporary_password(): void
    {
        $student = User::factory()->student()->create();

        $temporary = $this->accounts->resetPassword($this->admin(), $student);

        $this->assertSame(14, strlen($temporary));
        $this->assertTrue(Hash::check($temporary, $student->fresh()->password));
        $this->assertTrue($student->fresh()->must_change_password);
    }

    public function test_admin_cannot_act_on_an_account_with_an_assigned_role(): void
    {
        $admin = $this->admin();
        foreach (['ADMIN', 'ICTO_ADMIN', 'HR_STAFF'] as $role) {
            $target = User::factory()->employee()->withRole($role)->create();
            $this->assertFalse($this->accounts->canManage($admin, $target));
            foreach ($this->actions() as $name => $action) {
                try {
                    $action($admin, $target);
                    $this->fail("{$name} should be refused for {$role}.");
                } catch (AuthorizationException) {
                    $this->assertTrue(true);
                }
            }
            $fresh = $target->fresh();
            $this->assertNotSame('Changed', $fresh->first_name);
            $this->assertTrue($fresh->status_flag);
        }
    }

    public function test_actors_without_an_admin_role_cannot_act(): void
    {
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create();

        $this->assertFalse($this->accounts->canManage($student, $other));
        $this->refuses(fn () => $this->accounts->unlock($student, $other), AuthorizationException::class);
        $this->refuses(fn () => $this->accounts->create($student, [
            'email' => 'x@example.test', 'user_type' => 'Student', 'first_name' => 'N', 'last_name' => 'P', 'student_no' => '1',
        ]), AuthorizationException::class);
    }

    public function test_admin_cannot_change_roles(): void
    {
        $admin = $this->admin();
        $student = User::factory()->student()->create();
        app(AutomaticRoles::class)->sync($student);

        $this->refuses(fn () => $this->accounts->setRoles($admin, $student, ['ADMIN']), AuthorizationException::class);
        $this->assertSame(['STUDENT'], $student->roleNames());
    }

    public function test_icto_admin_can_do_everything(): void
    {
        $icto = $this->icto();
        $target = User::factory()->employee()->withRole('ADMIN')->create();

        foreach ($this->actions() as $action) {
            $action($icto, $target);
        }
        $this->accounts->setRoles($icto, $target, ['icto_admin', 'hr_staff', 'student', 'EMPLOYEE']);

        $this->assertTrue($this->accounts->canManage($icto, $target));
        $this->assertSame(['EMPLOYEE', 'HR_STAFF', 'ICTO_ADMIN'], $target->roleNames());

        $this->accounts->setRoles($icto, $target, []);
        $this->assertSame(['EMPLOYEE'], $target->roleNames());
        $this->refuses(fn () => $this->accounts->setRoles($icto, $target, ['NOPE']), ValidationException::class);
    }

    public function test_icto_admin_cannot_remove_their_own_role_or_disable_themselves(): void
    {
        $icto = $this->icto();

        $this->refuses(fn () => $this->accounts->setRoles($icto, $icto, ['ADMIN']), ValidationException::class, "You can't remove your own ICTO admin role.");
        $this->refuses(fn () => $this->accounts->setEnabled($icto, $icto, false), ValidationException::class, "You can't disable your own account.");

        $this->assertSame(['ICTO_ADMIN'], $icto->fresh()->roleNames());
        $this->assertTrue($icto->fresh()->status_flag);
        $this->accounts->setRoles($icto, $icto, ['ICTO_ADMIN', 'HR_STAFF']);
        $this->assertSame(['HR_STAFF', 'ICTO_ADMIN'], $icto->fresh()->roleNames());
    }

    public function test_every_action_is_logged_with_who_did_it(): void
    {
        $icto = $this->icto();
        $student = $this->accounts->create($icto, [
            'email' => 'log@example.test', 'user_type' => 'Student', 'first_name' => 'N', 'last_name' => 'P', 'student_no' => '2021-1',
        ])['user'];
        $this->accounts->update($icto, $student, $this->details($student, ['first_name' => 'M']));
        $this->accounts->unlock($icto, $student);
        $this->accounts->resetPassword($icto, $student);
        $this->accounts->requirePasswordChange($icto, $student);
        $this->accounts->setEnabled($icto, $student, false);
        $this->accounts->setRoles($icto, $student, ['HR_STAFF']);

        $logs = ActivityLog::where('user_id', $icto->id)->pluck('activity');
        $this->assertCount(7, $logs);
        $this->assertTrue($logs->every(fn ($text) => str_contains($text, '2021-1')));
        $this->assertTrue($logs->contains(fn ($text) => str_contains($text, 'Reset password')));
    }
}
