<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>No access · BulSU</title>
    @include('partials.style')
</head>
<body>
<main class="card" aria-labelledby="title">
    <p class="org">Bulacan State University</p>
    <h1 id="title">You don't have access to {{ $appName }}</h1>
    <p class="lead">You're signed in as <strong>{{ $displayName }}</strong>.</p>
    <p class="hint">If you need it, ask the app's administrator.</p>
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="secondary">Sign out</button>
    </form>
</main>
</body>
</html>
