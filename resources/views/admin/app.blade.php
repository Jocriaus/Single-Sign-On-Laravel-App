@extends('admin.layout')
@section('title', $app->name)
@section('content')
    <p><a href="/admin/apps">← Apps</a></p>
    <h1>{{ $app->name }}</h1>
    <p class="sub">Client id <code>{{ $app->id }}</code></p>

    @error('confirm')<p class="alert error" role="alert">{{ $message }}</p>@enderror

    <div class="grid-2">
        <section class="panel" aria-labelledby="settings-title">
            <h2 id="settings-title">Settings</h2>
            <form class="stack" method="post" action="/admin/apps/{{ $app->id }}">
                @csrf
                @include('admin.app-fields')
                <div class="actions"><button type="submit">Save changes</button></div>
            </form>
        </section>

        <div>
            <section class="panel" aria-labelledby="secret-title">
                <h2 id="secret-title">Client secret</h2>
                <p>The secret was shown once, when the app was registered. If it's lost or leaked, make a new one.
                    The old secret stops working at once, so update the app straight away.</p>
                <form method="post" action="/admin/apps/{{ $app->id }}/secret">
                    @csrf
                    <label class="check"><input type="checkbox" name="confirm" value="1">
                        <span>Yes, make a new secret. The app stops signing users in until it uses the new one.</span></label>
                    <button type="submit" class="secondary">Make a new secret</button>
                </form>
            </section>

            <section class="panel" aria-labelledby="remove-title">
                <h2 id="remove-title">Remove app</h2>
                <p>Removes the app and ends its sign-ins and tokens. Users can no longer sign in to it.</p>
                <form method="post" action="/admin/apps/{{ $app->id }}/delete">
                    @csrf
                    <label class="check"><input type="checkbox" name="confirm" value="1">
                        <span>Yes, remove this app. This can't be undone.</span></label>
                    <button type="submit" class="danger">Remove app</button>
                </form>
            </section>
        </div>
    </div>
@endsection
