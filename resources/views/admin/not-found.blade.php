@extends('admin.layout')
@section('title', 'Not found')
@section('content')
    <h1>Not found</h1>
    <p class="sub">{{ $message }}</p>
    <p><a href="/admin">Back to the overview</a></p>
@endsection
