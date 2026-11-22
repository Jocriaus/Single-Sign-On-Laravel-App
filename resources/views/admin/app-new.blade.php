@extends('admin.layout')
@section('title', 'Register app')
@section('content')
    <p><a href="/admin/apps">← Apps</a></p>
    <h1>Register app</h1>
    <p class="sub">You get a client id and a client secret for the app's developers. The secret is shown once.</p>

    <section class="panel">
        <form class="stack" method="post" action="/admin/apps">
            @csrf
            @include('admin.app-fields', ['app' => null])
            <div class="actions"><button type="submit">Register app</button></div>
        </form>
    </section>
@endsection
