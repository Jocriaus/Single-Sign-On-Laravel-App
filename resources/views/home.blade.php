<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Signed in · BulSU</title>
    @include('partials.style')
</head>
<body>
<main class="card" aria-labelledby="title">
    <p class="org">Bulacan State University</p>
    <h1 id="title">{{ $displayName }}</h1>
    @if (session('status'))
        <p class="alert info" role="status">{{ session('status') }}</p>
    @endif
    <p class="lead">You're signed in as <strong>{{ $username }}</strong>.
        University apps will sign you in without asking for your password.</p>
    @if ($isAdmin)
        <p><a href="/admin">Open the admin console</a></p>
    @endif
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="secondary">Sign out</button>
    </form>
    <p class="hint">On a shared computer, always sign out when you're done.</p>
</main>
</body>
</html>
