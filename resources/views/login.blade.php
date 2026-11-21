<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · BulSU</title>
    @include('partials.style')
</head>
<body>
<main class="card" aria-labelledby="title">
    <p class="org">Bulacan State University</p>
    <h1 id="title">Sign in</h1>
    @if (!empty($appName))
        <p class="lead">You're signing in to <strong>{{ $appName }}</strong>.</p>
    @else
        <p class="lead">Use your BulSU account for all university apps.</p>
    @endif

    @if (!empty($error))
        <p class="alert error" role="alert">{{ $error }}</p>
    @endif
    @if (!empty($info))
        <p class="alert info" role="status">{{ $info }}</p>
    @endif

    <form method="post" action="{{ route('login.attempt') }}">
        @csrf
        <label for="username">Student or employee number</label>
        <input id="username" name="username" type="text" autocomplete="username" required autofocus
               value="{{ old('username') }}" aria-describedby="username-hint">
        <p class="hint" id="username-hint" style="margin:0">Administrators: use your admin username.</p>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button type="submit">Sign in</button>
    </form>
    <p class="hint">Only enter your password on this page. University apps never ask for it.</p>
</main>
</body>
</html>
