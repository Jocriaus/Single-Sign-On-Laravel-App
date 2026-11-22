@extends('admin.layout')
@section('title', 'Apps')
@section('content')
    <div class="page-head">
        <div>
            <h1>Apps</h1>
            <p class="sub">University apps that sign users in through this server.</p>
        </div>
        <a class="button" href="/admin/apps/new">Register app</a>
    </div>

    <section class="panel">
        @if ($apps->isEmpty())
            <p class="muted">No apps yet. Register the first one.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Name</th><th>Client id</th><th>Who can sign in</th><th>Single logout</th><th>Registered</th></tr></thead>
                    <tbody>
                    @foreach ($apps as $app)
                        <tr>
                            <td><a href="/admin/apps/{{ $app->id }}">{{ $app->name }}</a></td>
                            <td class="mono">{{ $app->id }}</td>
                            <td>{{ $app->allowed_roles ? implode(', ', $app->allowed_roles) : 'Everyone' }}</td>
                            <td>
                                @if ($app->backchannel_logout_uri)
                                    <span class="badge ok">Yes</span>
                                @else
                                    <span class="badge">No</span>
                                @endif
                            </td>
                            <td class="mono">{{ $app->created_at?->format('Y-m-d') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
