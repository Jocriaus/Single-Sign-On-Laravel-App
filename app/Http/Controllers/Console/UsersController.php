<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\UserType;
use App\Sso\Accounts\AccountService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Thin screens over AccountService: every account rule lives there. */
class UsersController extends Controller
{
    private const FIELDS = [
        'username', 'email', 'user_type', 'first_name', 'middle_name', 'middle_initial', 'last_name', 'suffix',
        'student_no', 'employee_no',
    ];

    public function __construct(private AccountService $accounts) {}

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()->with(['userType', 'roles'])
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.mb_strtolower($q).'%';
                $query->where(function ($w) use ($like) {
                    foreach (['username', 'email', 'first_name', 'last_name', 'student_no', 'employee_no'] as $column) {
                        $w->orWhereRaw("LOWER({$column}) LIKE ?", [$like]);
                    }
                });
            })
            ->orderBy('last_name')->orderBy('first_name')->orderBy('username')
            ->simplePaginate(25)->withQueryString();

        return view('admin.users', ['users' => $users, 'q' => $q]);
    }

    public function create(Request $request)
    {
        return view('admin.user-new', ['typeChoices' => $this->typeChoices($request->user())]);
    }

    public function store(Request $request)
    {
        $created = $this->guard(fn () => $this->accounts->create($request->user(), $this->data($request)), route('admin.users.new'));

        return $this->passwordPage($created['user'], $created['temporaryPassword'], true);
    }

    public function show(Request $request, string $user)
    {
        $account = $this->find($user);
        $actor = $request->user();

        return view('admin.user', [
            'account' => $account,
            'canManage' => $this->accounts->canManage($actor, $account),
            'isIcto' => $actor->hasRole('ICTO_ADMIN'),
            'typeChoices' => $this->typeChoices($actor, $account),
            'roleChoices' => Role::orderBy('role_name')->pluck('role_name')->all(),
        ]);
    }

    public function update(Request $request, string $user)
    {
        $account = $this->find($user);
        $this->guard(fn () => $this->accounts->update($request->user(), $account, $this->data($request)), $this->url($account));

        return $this->done($account, 'Saved.');
    }

    public function unlock(Request $request, string $user)
    {
        $account = $this->find($user);
        $this->guard(fn () => $this->accounts->unlock($request->user(), $account), $this->url($account));

        return $this->done($account, 'Unlocked.');
    }

    public function requirePasswordChange(Request $request, string $user)
    {
        $account = $this->find($user);
        $this->guard(fn () => $this->accounts->requirePasswordChange($request->user(), $account), $this->url($account));

        return $this->done($account, "They'll be asked for a new password at next sign-in.");
    }

    public function resetPassword(Request $request, string $user)
    {
        $account = $this->find($user);
        $this->manageable($request, $account);
        $this->confirmed($request);
        $password = $this->guard(fn () => $this->accounts->resetPassword($request->user(), $account), $this->url($account));

        return $this->passwordPage($account, $password, false);
    }

    public function disable(Request $request, string $user)
    {
        $account = $this->find($user);
        $this->manageable($request, $account);
        $this->confirmed($request);
        $this->guard(fn () => $this->accounts->setEnabled($request->user(), $account, false), $this->url($account));

        return $this->done($account, 'Disabled.');
    }

    public function enable(Request $request, string $user)
    {
        $account = $this->find($user);
        $this->guard(fn () => $this->accounts->setEnabled($request->user(), $account, true), $this->url($account));

        return $this->done($account, 'Enabled.');
    }

    public function roles(Request $request, string $user)
    {
        $account = $this->find($user);
        $this->guard(fn () => $this->accounts->setRoles($request->user(), $account, (array) $request->input('roles', [])), $this->url($account));

        return $this->done($account, 'Roles saved.');
    }

    /** Runs a service call; a rejected one goes back to the given page with its errors and the typed input. */
    private function guard(callable $call, string $backTo): mixed
    {
        try {
            return $call();
        } catch (ValidationException $e) {
            throw $e->redirectTo($backTo);
        }
    }

    private function find(string $id): User
    {
        return (ctype_digit($id) ? User::find($id) : null) ?? abort(response()->view('admin.not-found', [
            'message' => "That user doesn't exist. They may have been removed.",
        ], 404));
    }

    /** Refuse before the confirmation check, so a protected account answers 403 whatever was sent. */
    private function manageable(Request $request, User $account): void
    {
        abort_unless($this->accounts->canManage($request->user(), $account), 403);
    }

    private function confirmed(Request $request): void
    {
        $request->validate(['confirm' => 'accepted'], ['confirm.accepted' => 'Tick the box to confirm.']);
    }

    private function done(User $account, string $message)
    {
        return redirect($this->url($account))->with('status', $message);
    }

    private function url(User $account): string
    {
        return route('admin.users.show', $account->id);
    }

    private function data(Request $request): array
    {
        return array_map(fn ($key) => $request->input($key), array_combine(self::FIELDS, self::FIELDS));
    }

    /** @return list<string> */
    private function typeChoices(User $actor, ?User $account = null): array
    {
        return UserType::orderBy('id')->pluck('user_type_name')
            ->filter(fn ($name) => $name !== UserType::ADMIN || $actor->hasRole('ICTO_ADMIN') || $account?->userType?->user_type_name === UserType::ADMIN)
            ->values()->all();
    }

    /** The temporary password goes only into this response, never into the session. */
    private function passwordPage(User $account, string $temporaryPassword, bool $isNew)
    {
        return response()
            ->view('admin.user-password', ['account' => $account, 'temporaryPassword' => $temporaryPassword, 'isNew' => $isNew])
            ->header('Cache-Control', 'no-store, private');
    }
}
