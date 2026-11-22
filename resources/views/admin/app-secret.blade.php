@extends('admin.layout')
@section('title', $isNew ? 'App registered' : 'New secret')
@section('content')
    <h1>{{ $isNew ? $app->name.' is registered' : 'New secret for '.$app->name }}</h1>
    <p class="sub">Give these to the app's developers. They go in the app's environment settings, never in git.</p>

    <section class="panel">
        <p class="alert info" role="status"><strong>Copy the secret now.</strong> It's shown only this once;
            if it's lost, make a new one from the app's page.</p>

        <label>Client id</label>
        <code class="secret" id="client-id">{{ $app->id }}</code>

        <label>Client secret</label>
        <code class="secret" id="client-secret">{{ $secret }}</code>

        <div class="actions">
            <a class="button" href="/admin/apps/{{ $app->id }}">Done</a>
        </div>
    </section>
@endsection
