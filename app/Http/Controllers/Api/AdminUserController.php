<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserType;
use App\Sso\Accounts\AccountService;
use App\Sso\Accounts\Lockout;
use App\Sso\Accounts\UserClaims;
use App\Sso\Api\ApiResponse;
use App\Sso\Api\Paging;
use App\Sso\Api\RequestFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Thin JSON over AccountService: every account rule lives there. */
class AdminUserController extends Controller
{
    private const FIELDS = [
        'username' => 'username', 'email' => 'email', 'userType' => 'user_type',
        'firstName' => 'first_name', 'middleName' => 'middle_name', 'middleInitial' => 'middle_initial',
        'lastName' => 'last_name', 'suffix' => 'suffix',
        'studentNumber' => 'student_no', 'employeeNumber' => 'employee_no',
    ];

    public function __construct(private AccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->query('q');
        $query = User::query()->with(['userType', 'roles'])
            ->when(is_string($q) && trim($q) !== '', fn ($query) => $query->search(trim($q)))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('username')->orderBy('id');

        return ApiResponse::success('Users loaded.', Paging::of($query, $request, fn (User $user) => $this->present($user)));
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::success('User loaded.', $this->present($this->find($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $created = $this->accounts->create($request->user(), $this->data($request));

        return ApiResponse::success('User created.', $this->present($this->reloaded($created['user'])) + ['temporaryPassword' => $created['temporaryPassword']])
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->accounts->update($request->user(), $this->find($id), $this->data($request));

        return ApiResponse::success('User updated.', $this->present($this->reloaded($user)));
    }

    public function lock(Request $request, string $id): JsonResponse
    {
        return $this->change($request, $id, 'User disabled.', fn ($user) => $this->accounts->setEnabled($request->user(), $user, false));
    }

    public function enable(Request $request, string $id): JsonResponse
    {
        return $this->change($request, $id, 'User enabled.', fn ($user) => $this->accounts->setEnabled($request->user(), $user, true));
    }

    public function unlock(Request $request, string $id): JsonResponse
    {
        return $this->change($request, $id, 'User unlocked.', fn ($user) => $this->accounts->unlock($request->user(), $user));
    }

    public function forcePasswordReset(Request $request, string $id): JsonResponse
    {
        return $this->change($request, $id, 'The user must choose a new password at next sign-in.', fn ($user) => $this->accounts->requirePasswordChange($request->user(), $user));
    }

    private function change(Request $request, string $id, string $message, callable $action): JsonResponse
    {
        $user = $this->find($id);
        $action($user);

        return ApiResponse::success($message, $this->present($user->refresh()));
    }

    /** The service changed roles and type behind this instance's back. */
    private function reloaded(User $user): User
    {
        return $user->unsetRelations()->load(['userType', 'roles']);
    }

    private function find(string $id): User
    {
        return (ctype_digit($id) && strlen($id) <= 18 ? User::with(['userType', 'roles'])->find($id) : null) ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $data = RequestFields::pick($request, self::FIELDS + ['userTypeId' => 'user_type_id']);

        if (! array_key_exists('user_type', $data) && array_key_exists('user_type_id', $data)) {
            $id = $data['user_type_id'];
            $data['user_type'] = is_scalar($id) && ctype_digit((string) $id) && strlen((string) $id) <= 18
                ? UserType::find((string) $id)?->user_type_name
                : null;
        }
        unset($data['user_type_id']);

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'userType' => UserClaims::userType($user),
            'roles' => $user->roles->pluck('role_name')->map(fn ($name) => strtoupper($name))->unique()->sort()->values()->all(),
            'name' => UserClaims::displayName($user),
            'firstName' => $user->first_name,
            'middleName' => $user->middle_name,
            'middleInitial' => $user->middle_initial,
            'lastName' => $user->last_name,
            'suffix' => $user->suffix,
            'studentNumber' => $user->student_no,
            'employeeNumber' => $user->employee_no,
            'enabled' => (bool) $user->status_flag,
            'locked' => Lockout::isLocked($user),
            'mustChangePassword' => (bool) $user->must_change_password,
            'createdAt' => $user->created_at?->toIso8601String(),
        ];
    }
}
