@extends('admin.layout')
@section('title', 'Overview')
@section('content')
    <h1>Overview</h1>
    <p class="sub">Accounts and apps on the BulSU single sign-on server.</p>

    <div class="stats">
        <div class="stat"><b>{{ $userCount }}</b><span>Accounts</span></div>
        @if ($appCount !== null)
            <a class="stat" href="/admin/apps"><b>{{ $appCount }}</b><span>Apps</span></a>
        @endif
        <div class="stat"><b>{{ $lockedCount }}</b><span>Locked accounts</span></div>
        <div class="stat"><b>{{ $mustChangeCount }}</b><span>Must choose a new password</span></div>
        <div class="stat"><b>{{ $disabledCount }}</b><span>Disabled accounts</span></div>
    </div>

    <section class="panel" aria-labelledby="activity-title">
        <h2 id="activity-title">Recent activity</h2>
        @if ($activity->isEmpty())
            <p class="muted">Nothing yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead>
                    <tbody>
                    @foreach ($activity as $log)
                        <tr>
                            <td class="mono">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                            <td>{{ $log->user?->username ?? '—' }}</td>
                            <td>{{ $log->activity }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
