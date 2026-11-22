@extends('admin.layout')
@section('title', \App\Sso\Accounts\UserClaims::displayName($account))
@section('content')
    <p><a href="/admin/users">← Users</a></p>
    <h1>{{ \App\Sso\Accounts\UserClaims::displayName($account) }}</h1>
    <p class="sub">@include('admin.user-state')</p>

    @foreach (['roles', 'status', 'confirm'] as $key)
        @error($key)<p class="alert error" role="alert">{{ $message }}</p>@enderror
    @endforeach

    <div class="grid-2">
        <section class="panel" aria-labelledby="identity-title">
            <h2 id="identity-title">Identity</h2>
            @if ($canManage)
                <form class="stack" method="post" action="/admin/users/{{ $account->id }}">
                    @csrf
                    @include('admin.user-fields')
                    <div class="actions"><button type="submit">Save changes</button></div>
                </form>
            @else
                <p class="help">This account is protected. Only an ICTO admin can change it.</p>
                <table>
                    <tbody>
                    <tr><th>Type</th><td>{{ $account->userType?->user_type_name }}</td></tr>
                    <tr><th>Sign-in number</th><td class="mono">{{ $account->username }}</td></tr>
                    <tr><th>Student number</th><td class="mono">{{ $account->student_no }}</td></tr>
                    <tr><th>Employee number</th><td class="mono">{{ $account->employee_no }}</td></tr>
                    <tr><th>Email</th><td>{{ $account->email }}</td></tr>
                    <tr><th>Roles</th><td>{{ implode(', ', $account->roleNames()) }}</td></tr>
                    </tbody>
                </table>
            @endif
        </section>

        <div>
            @if ($isIcto)
                @php($assigned = $account->roleNames())
                <section class="panel" aria-labelledby="roles-title">
                    <h2 id="roles-title">Roles</h2>
                    <form method="post" action="/admin/users/{{ $account->id }}/roles">
                        @csrf
                        @foreach ($roleChoices as $role)
                            @if (in_array($role, \App\Models\Role::AUTOMATIC, true))
                                <p class="check muted">{{ $role }}: automatic, from the student or employee number.</p>
                            @else
                                <label class="check"><input type="checkbox" name="roles[]" value="{{ $role }}" @checked(in_array($role, $assigned, true))>
                                    <span>{{ $role }}</span></label>
                            @endif
                        @endforeach
                        <div class="actions"><button type="submit">Save roles</button></div>
                    </form>
                </section>
            @endif

            @if ($canManage)
                <section class="panel" aria-labelledby="actions-title">
                    <h2 id="actions-title">Account actions</h2>
                    <div class="actions">
                        <form method="post" action="/admin/users/{{ $account->id }}/unlock">@csrf<button type="submit" class="secondary">Unlock</button></form>
                        <form method="post" action="/admin/users/{{ $account->id }}/require-password-change">@csrf<button type="submit" class="secondary">Require password change</button></form>
                        @unless ($account->status_flag)
                            <form method="post" action="/admin/users/{{ $account->id }}/enable">@csrf<button type="submit" class="secondary">Enable</button></form>
                        @endunless
                    </div>
                </section>

                <section class="panel" aria-labelledby="reset-title">
                    <h2 id="reset-title">Reset password</h2>
                    <p>Makes a new temporary password, shown once. The old password stops working at once.</p>
                    <form method="post" action="/admin/users/{{ $account->id }}/reset-password">
                        @csrf
                        <label class="check"><input type="checkbox" name="confirm" value="1">
                            <span>Tick the box to confirm.</span></label>
                        <button type="submit" class="secondary">Reset password</button>
                    </form>
                </section>

                @if ($account->status_flag)
                    <section class="panel" aria-labelledby="disable-title">
                        <h2 id="disable-title">Disable account</h2>
                        <p>They are signed out of every app and cannot sign in until the account is enabled again.</p>
                        <form method="post" action="/admin/users/{{ $account->id }}/disable">
                            @csrf
                            <label class="check"><input type="checkbox" name="confirm" value="1">
                                <span>Tick the box to confirm.</span></label>
                            <button type="submit" class="danger">Disable account</button>
                        </form>
                    </section>
                @endif
            @endif
        </div>
    </div>
@endsection
