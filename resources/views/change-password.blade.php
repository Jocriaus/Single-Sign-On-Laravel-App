<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change password · BulSU</title>
    @include('partials.style')
</head>
<body>
<main class="card" aria-labelledby="title">
    <p class="org">Bulacan State University</p>
    <h1 id="title">Choose a new password</h1>
    @if ($required)
        <p class="lead">Before you continue, replace your current password with one only you know.</p>
    @else
        <p class="lead">Your new password works in every university app.</p>
    @endif

    @if (!empty($problems))
        <div class="alert error" role="alert">
            @foreach ($problems as $message)
                <p style="margin:0">{{ $message }}</p>
            @endforeach
        </div>
    @endif

    <form method="post" action="{{ route('password.update') }}">
        @csrf
        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required autofocus>

        <label for="new_password">New password</label>
        <input id="new_password" name="new_password" type="password" autocomplete="new-password" required
               minlength="{{ $minLength }}" aria-describedby="rules">

        <label for="new_password_confirmation">Type the new password again</label>
        <input id="new_password_confirmation" name="new_password_confirmation" type="password" autocomplete="new-password" required>

        <button type="submit">Save new password</button>
    </form>
    <p class="hint" id="rules">At least {{ $minLength }} characters. A short sentence
        is easy to remember and hard to guess. Don't use your username or a common password.</p>
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="secondary">Sign out</button>
    </form>
</main>
</body>
</html>
