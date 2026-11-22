@extends('admin.layout')
@section('title', 'Temporary password')
@section('content')
    <h1>{{ $isNew ? 'Account created for '.\App\Sso\Accounts\UserClaims::displayName($account) : 'New password for '.\App\Sso\Accounts\UserClaims::displayName($account) }}</h1>
    <p class="sub">Give these to the person. They must choose their own password when they sign in.</p>

    <section class="panel">
        <p class="alert info" role="status"><strong>Copy the password now.</strong> It's shown only this once;
            if it's lost, reset the password from the user's page.</p>

        <label>Sign-in number</label>
        <code class="secret" id="sign-in-number">{{ $account->username }}</code>

        <label>Temporary password</label>
        <code class="secret" id="temporary-password">{{ $temporaryPassword }}</code>

        <div class="actions">
            <a class="button" href="/admin/users/{{ $account->id }}">Done</a>
        </div>
    </section>
@endsection
