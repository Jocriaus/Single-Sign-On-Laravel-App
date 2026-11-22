<?php

namespace Tests\Feature\Accounts;

use App\Models\User;
use App\Sso\Accounts\AccountService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\OAuthFlow;
use Tests\TestCase;

class AccountRulesTest extends TestCase
{
    use OAuthFlow;

    private User $actor;

    private AccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actor = User::findByUsername('admin');
        $this->accounts = app(AccountService::class);
    }

    private function data(array $overrides = []): array
    {
        return $overrides + [
            'email' => fake()->unique()->safeEmail(),
            'user_type' => 'Student',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'student_no' => '2021-00001',
        ];
    }

    private function assertInvalid(string $key, callable $action, ?string $message = null): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
            if ($message !== null) {
                $this->assertSame($message, $e->errors()[$key][0]);
            }

            return;
        }
        $this->fail("Expected a ValidationException on {$key}.");
    }

    public function test_student_needs_a_student_number_and_employee_an_employee_number(): void
    {
        $this->assertInvalid('student_no', fn () => $this->accounts->create($this->actor, $this->data(['student_no' => ''])), 'A student needs a student number.');
        $this->assertInvalid('employee_no', fn () => $this->accounts->create($this->actor, $this->data([
            'user_type' => 'Employee', 'student_no' => null, 'employee_no' => null,
        ])), 'An employee needs an employee number.');
        $this->assertInvalid('user_type', fn () => $this->accounts->create($this->actor, $this->data(['user_type' => 'Alien'])));
    }

    public function test_one_person_may_hold_both_numbers_and_gets_both_roles(): void
    {
        $user = $this->accounts->create($this->actor, $this->data(['employee_no' => 'E-1']))['user'];

        $this->assertSame(['EMPLOYEE', 'STUDENT'], $user->roleNames());

        $employee = $this->accounts->create($this->actor, $this->data([
            'user_type' => 'Employee', 'student_no' => '2021-00002', 'employee_no' => 'E-2',
        ]))['user'];
        $this->assertSame(['EMPLOYEE', 'STUDENT'], $employee->roleNames());
    }

    public function test_admin_type_accounts_carry_no_numbers(): void
    {
        $adminData = ['user_type' => 'Admin', 'username' => 'ops', 'first_name' => null, 'last_name' => null];

        $this->assertInvalid('student_no', fn () => $this->accounts->create($this->actor, $this->data($adminData)), "Admin accounts don't carry student or employee numbers.");
        $this->assertInvalid('employee_no', fn () => $this->accounts->create($this->actor, $this->data($adminData + ['student_no' => null, 'employee_no' => 'E-9'])));
        $this->assertInvalid('username', fn () => $this->accounts->create($this->actor, $this->data(['username' => '', 'student_no' => null] + $adminData)), 'A sign-in name is required for admin accounts.');

        $ops = $this->accounts->create($this->actor, $this->data(['student_no' => null] + $adminData))['user'];
        $this->assertSame('ops', $ops->username);
        $this->assertSame([], $ops->roleNames());

        $this->assertInvalid('student_no', fn () => $this->accounts->update($this->actor, $ops, $this->data($adminData)));
    }

    public function test_names_are_required_except_for_admin_type(): void
    {
        $this->assertInvalid('first_name', fn () => $this->accounts->create($this->actor, $this->data(['first_name' => ' '])), 'First name is required.');
        $this->assertInvalid('last_name', fn () => $this->accounts->create($this->actor, $this->data(['last_name' => null])), 'Last name is required.');

        $ops = $this->accounts->create($this->actor, [
            'email' => 'ops@example.test', 'user_type' => 'Admin', 'username' => 'ops',
        ])['user'];
        $this->assertNull($ops->first_name);
    }

    public function test_numbers_are_unique_within_their_kind_but_may_repeat_across_kinds(): void
    {
        $this->accounts->create($this->actor, $this->data(['student_no' => '100', 'employee_no' => '200']));

        $this->assertInvalid('student_no', fn () => $this->accounts->create($this->actor, $this->data(['student_no' => '100', 'username' => 'other'])), 'This student number is already used by another account.');
        $this->assertInvalid('employee_no', fn () => $this->accounts->create($this->actor, $this->data(['user_type' => 'Employee', 'student_no' => null, 'employee_no' => '200'])), 'This employee number is already used by another account.');

        $across = $this->accounts->create($this->actor, $this->data(['student_no' => '200']))['user'];
        $this->assertSame('200', $across->student_no);
        $this->assertInvalid('email', fn () => $this->accounts->create($this->actor, $this->data([
            'student_no' => '300', 'email' => $across->email,
        ])), 'This email is already used by another account.');
    }

    public function test_sign_in_number_defaults_to_the_main_number(): void
    {
        $employee = $this->accounts->create($this->actor, $this->data([
            'user_type' => 'Employee', 'student_no' => '2021-00003', 'employee_no' => 'E-3',
        ]))['user'];
        $student = $this->accounts->create($this->actor, $this->data(['student_no' => '2021-00004']))['user'];

        $this->assertSame('E-3', $employee->username);
        $this->assertSame('2021-00004', $student->username);

        $kept = $this->accounts->update($this->actor, $student, $this->data([
            'email' => $student->email, 'student_no' => '2021-00004', 'username' => '',
        ]));
        $this->assertSame('2021-00004', $kept->username);
    }

    public function test_duplicate_sign_in_number_is_refused_with_the_agreed_message(): void
    {
        $this->accounts->create($this->actor, $this->data(['student_no' => '1']));

        $this->assertInvalid('username', fn () => $this->accounts->create($this->actor, $this->data(['student_no' => '2', 'username' => '1'])), 'This sign-in number is already used by another account.');
        $this->assertInvalid('username', fn () => $this->accounts->create($this->actor, $this->data(['student_no' => '3', 'username' => 'ADMIN'])), 'This sign-in number is already used by another account.');
        $this->assertDatabaseMissing('users', ['student_no' => '2']);
    }

    public function test_updating_keeps_the_persons_own_numbers_valid(): void
    {
        $user = $this->accounts->create($this->actor, $this->data(['employee_no' => 'E-7']))['user'];

        $again = $this->accounts->update($this->actor, $user, $this->data([
            'email' => $user->email, 'employee_no' => 'E-7', 'username' => $user->username,
        ]));

        $this->assertSame('E-7', $again->employee_no);
        $this->assertSame(['EMPLOYEE', 'STUDENT'], $again->roleNames());

        $dropped = $this->accounts->update($this->actor, $again, $this->data(['email' => $user->email, 'employee_no' => '']));
        $this->assertNull($dropped->employee_no);
        $this->assertSame(['STUDENT'], $dropped->roleNames());
    }

    public function test_middle_initial_is_kept_as_entered(): void
    {
        $user = $this->accounts->create($this->actor, $this->data(['middle_name' => 'Dela Cruz', 'middle_initial' => 'DC', 'suffix' => '']))['user'];

        $this->assertSame('DC', $user->fresh()->middle_initial);
        $this->assertNull($user->fresh()->suffix);
    }

    public function test_new_account_gets_a_temporary_password_and_must_change_it(): void
    {
        $result = $this->accounts->create($this->actor, $this->data());

        $this->assertSame(14, strlen($result['temporaryPassword']));
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789]{14}$/', $result['temporaryPassword']);
        $this->assertTrue(Hash::check($result['temporaryPassword'], $result['user']->fresh()->password));
        $this->assertTrue($result['user']->fresh()->must_change_password);
        $this->assertTrue($result['user']->fresh()->status_flag);
    }

    public function test_changing_the_sign_in_number_signs_the_person_out_everywhere(): void
    {
        Http::fake();
        $app = $this->registerApp(['backchannel_logout_uri' => 'https://app.test/backchannel-logout']);
        $user = User::factory()->student()->create();
        $tokens = $this->tokensFor($user, $app);

        $this->accounts->update($this->actor, $user, $this->data([
            'email' => $user->email, 'student_no' => $user->student_no, 'username' => 'new-number',
        ]));
        $this->app->terminate();

        $this->assertSame('new-number', $user->fresh()->username);
        $this->refresh($app, $tokens['refresh_token'])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://app.test/backchannel-logout');
    }

    public function test_changing_other_fields_does_not_sign_the_person_out(): void
    {
        Http::fake();
        $app = $this->registerApp(['backchannel_logout_uri' => 'https://app.test/backchannel-logout']);
        $user = User::factory()->student()->create();
        $this->tokensFor($user, $app);

        $this->accounts->update($this->actor, $user, $this->data([
            'email' => $user->email, 'student_no' => $user->student_no, 'username' => strtoupper($user->username),
            'first_name' => 'Changed',
        ]));
        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_a_refused_sign_in_number_change_signs_nobody_out(): void
    {
        Http::fake();
        $app = $this->registerApp(['backchannel_logout_uri' => 'https://app.test/backchannel-logout']);
        $user = User::factory()->student()->create();
        User::factory()->create(['username' => 'taken']);
        $tokens = $this->tokensFor($user, $app);

        $this->assertInvalid('username', fn () => $this->accounts->update($this->actor, $user, $this->data([
            'email' => $user->email, 'student_no' => $user->student_no, 'username' => 'TAKEN',
        ])), 'This sign-in number is already used by another account.');
        $this->app->terminate();

        Http::assertNothingSent();
        $this->assertNotSame('TAKEN', $user->fresh()->username);
        $this->refresh($app, $tokens['refresh_token'])->assertOk();
    }

    public function test_only_icto_admins_create_or_switch_to_admin_type_accounts(): void
    {
        $admin = User::factory()->employee()->withRole('ADMIN')->create();
        $adminData = ['user_type' => 'Admin', 'username' => 'ops2', 'student_no' => null];

        $this->assertInvalid('user_type', fn () => $this->accounts->create($admin, $this->data($adminData)), 'Only ICTO admins can create admin-type accounts.');

        $student = User::factory()->student()->create();
        $this->assertInvalid('user_type', fn () => $this->accounts->update($admin, $student, $this->data([
            'email' => $student->email, 'username' => $student->username,
        ] + $adminData)), 'Only ICTO admins can create admin-type accounts.');
        $this->assertSame('Student', $student->fresh()->userType->user_type_name);

        $this->assertSame('Admin', $this->accounts->create($this->actor, $this->data($adminData))['user']->userType->user_type_name);
    }

    public function test_disabling_a_person_signs_them_out_of_every_app(): void
    {
        Http::fake();
        $app = $this->registerApp(['backchannel_logout_uri' => 'https://app.test/backchannel-logout']);
        $user = User::factory()->student()->create();
        $tokens = $this->tokensFor($user, $app);

        $this->accounts->setEnabled($this->actor, $user, false);
        $this->app->terminate();

        $this->refresh($app, $tokens['refresh_token'])->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://app.test/backchannel-logout');
    }

    public function test_extra_keys_in_the_data_are_ignored(): void
    {
        $extras = [
            'password' => 'hacked-password-123', 'status_flag' => false, 'must_change_password' => false,
            'roles' => ['ICTO_ADMIN'], 'id' => 9999,
        ];

        $user = $this->accounts->create($this->actor, $this->data($extras))['user']->fresh();

        $this->assertNotSame(9999, $user->id);
        $this->assertTrue($user->status_flag);
        $this->assertTrue($user->must_change_password);
        $this->assertFalse(Hash::check('hacked-password-123', $user->password));
        $this->assertSame(['STUDENT'], $user->roleNames());

        $hash = $user->password;
        $this->accounts->update($this->actor, $user, $this->data(['email' => $user->email, 'student_no' => $user->student_no] + $extras));
        $user = $user->fresh();
        $this->assertSame($hash, $user->password);
        $this->assertTrue($user->status_flag);
        $this->assertTrue($user->must_change_password);
        $this->assertSame(['STUDENT'], $user->roleNames());
    }
}
