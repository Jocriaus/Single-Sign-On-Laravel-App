<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign-in request not valid · BulSU</title>
    @include('partials.style')
</head>
<body>
<main class="card" aria-labelledby="title">
    <p class="org">Bulacan State University</p>
    <h1 id="title">Can't sign in to this app</h1>
    <p class="alert error" role="alert">This sign-in request isn't valid.</p>
    <p class="lead">{{ $reason }}</p>
    <p class="hint">Go back to the app and try again. If this keeps happening, tell the app's administrator.</p>
</main>
</body>
</html>
