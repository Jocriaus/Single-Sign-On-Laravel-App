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
    <h1 id="title">No access</h1>
    <p class="lead">You don't have access to this page.</p>
    @auth
        <p class="hint">You're signed in as <strong>{{ auth()->user()->username }}</strong>.</p>
    @endauth
    <p class="hint"><a href="/">Back to your account</a></p>
    @auth
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="secondary">Sign out</button>
        </form>
    @endauth
</main>
</body>
</html>
