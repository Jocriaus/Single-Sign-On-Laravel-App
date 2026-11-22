@extends('admin.layout')
@section('title', 'Add user')
@section('content')
    <p><a href="/admin/users">← Users</a></p>
    <h1>Add user</h1>
    <p class="sub">They get a temporary password and must choose their own at first sign-in.</p>

    <section class="panel">
        <form class="stack" method="post" action="/admin/users">
            @csrf
            @include('admin.user-fields')
            <div class="actions"><button type="submit">Add user</button></div>
        </form>
    </section>
@endsection
