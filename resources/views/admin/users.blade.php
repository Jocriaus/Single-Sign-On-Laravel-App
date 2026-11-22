@extends('admin.layout')
@section('title', 'Users')
@section('content')
    <div class="page-head">
        <div>
            <h1>Users</h1>
            <p class="sub">BulSU accounts that can sign in to university apps.</p>
        </div>
        <a class="button" href="/admin/users/new">Add user</a>
    </div>

    <form class="stack" method="get" action="/admin/users" role="search">
        <label for="q">Search users</label>
        <input id="q" name="q" type="search" value="{{ $q }}" placeholder="Name, number or email">
        <div class="actions"><button type="submit" class="secondary">Search</button></div>
    </form>

    <section class="panel">
        @if ($users->isEmpty())
            <p class="muted">No users match.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Sign-in number</th><th>Student number</th><th>Employee number</th><th>Type</th><th>Roles</th><th>State</th></tr></thead>
                    <tbody>
                    @foreach ($users as $u)
                        <tr>
                            <td><a href="/admin/users/{{ $u->id }}">{{ \App\Sso\Accounts\UserClaims::displayName($u) }}</a><br><span class="muted">{{ $u->email }}</span></td>
                            <td class="mono">{{ $u->username }}</td>
                            <td class="mono">{{ $u->student_no }}</td>
                            <td class="mono">{{ $u->employee_no }}</td>
                            <td>{{ $u->userType?->user_type_name }}</td>
                            <td>{{ implode(', ', $u->roleNames()) }}</td>
                            <td>@include('admin.user-state', ['account' => $u])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($users->currentPage() > 1 || $users->hasMorePages())
                <p class="actions">
                    @if ($users->currentPage() > 1)
                        <a href="/admin/users?{{ http_build_query(array_filter(['q' => $q, 'page' => $users->currentPage() - 1])) }}">← Previous</a>
                    @endif
                    <span class="muted">Page {{ $users->currentPage() }}</span>
                    @if ($users->hasMorePages())
                        <a href="/admin/users?{{ http_build_query(array_filter(['q' => $q, 'page' => $users->currentPage() + 1])) }}">Next →</a>
                    @endif
                </p>
            @endif
        @endif
    </section>
@endsection
