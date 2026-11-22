<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') · BulSU SSO admin</title>
    <style>
        :root {
            --bg: #f3f4f6; --card: #ffffff; --fg: #1c1f24; --muted: #5b6270; --line: #d7dbe1; --soft: #eef0f3;
            --accent: #7a1f2b; --accent-fg: #ffffff;
            --error-bg: #fbe8ea; --error-fg: #8e1b29; --info-bg: #e5eff8; --info-fg: #1f4e79;
            --ok-bg: #e4f3e8; --ok-fg: #1d5e33; --warn-bg: #fdf1dc; --warn-fg: #7a4b00;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #111318; --card: #1b1e25; --fg: #e8eaee; --muted: #a2a9b5; --line: #343944; --soft: #23272f;
                --accent: #d4707d; --accent-fg: #111318;
                --error-bg: #3a1c21; --error-fg: #f3a7b0; --info-bg: #1a2a3a; --info-fg: #a9cbea;
                --ok-bg: #17301f; --ok-fg: #9fd8b0; --warn-bg: #33270f; --warn-fg: #f0c878;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        a { color: var(--accent); }
        .topbar { background: var(--card); border-bottom: 1px solid var(--line); }
        .topbar-inner { max-width: 1080px; margin: 0 auto; padding: 10px 16px; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 20px; }
        .brand { font-weight: 700; color: var(--fg); text-decoration: none; margin-right: 8px; }
        .brand span { color: var(--accent); }
        nav.sections { display: flex; gap: 4px; flex-wrap: wrap; }
        nav.sections a { padding: 6px 12px; border-radius: 6px; text-decoration: none; color: var(--muted); font-weight: 600; }
        nav.sections a[aria-current="page"] { background: var(--soft); color: var(--fg); }
        .who { margin-left: auto; display: flex; align-items: center; gap: 12px; color: var(--muted); font-size: 14px; }
        .who form { margin: 0; }
        main { max-width: 1080px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 24px; margin: 0 0 4px; line-height: 1.25; }
        h2 { font-size: 17px; margin: 0 0 12px; }
        .sub { color: var(--muted); margin: 0 0 20px; }
        .page-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        .page-head .sub { margin: 0; }
        .panel { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 20px; margin-bottom: 20px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .stat { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; text-decoration: none; color: var(--fg); }
        .stat b { display: block; font-size: 26px; line-height: 1.2; }
        .stat span { color: var(--muted); font-size: 14px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { font-size: 13px; color: var(--muted); font-weight: 600; white-space: nowrap; }
        tr:last-child td { border-bottom: 0; }
        code, .mono { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 13px; overflow-wrap: anywhere; }
        .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; background: var(--soft); color: var(--muted); margin: 0 4px 2px 0; }
        .badge.ok { background: var(--ok-bg); color: var(--ok-fg); }
        .alert { margin: 0 0 16px; padding: 10px 12px; border-radius: 8px; }
        .alert.error { background: var(--error-bg); color: var(--error-fg); }
        .alert.info { background: var(--info-bg); color: var(--info-fg); }
        .alert.ok { background: var(--ok-bg); color: var(--ok-fg); }
        .field-error { color: var(--error-fg); font-size: 13px; margin: 2px 0 0; }
        form.stack { display: grid; gap: 4px; max-width: 640px; }
        label { font-weight: 600; font-size: 14px; margin-top: 10px; }
        .help { color: var(--muted); font-size: 13px; margin: 0 0 2px; }
        input[type=text], input[type=email], input[type=url], input[type=search], select, textarea {
            font: inherit; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--card); color: var(--fg); width: 100%;
        }
        textarea { min-height: 72px; font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 13px; }
        fieldset { border: 1px solid var(--line); border-radius: 8px; padding: 8px 12px 12px; margin: 10px 0 0; }
        legend { font-weight: 600; font-size: 14px; padding: 0 4px; }
        .check { display: flex; gap: 8px; align-items: flex-start; font-weight: 400; margin-top: 6px; }
        .check input { margin-top: 4px; }
        button, .button {
            display: inline-block; font: inherit; font-weight: 600; padding: 8px 14px; border-radius: 8px; border: 1px solid var(--accent);
            background: var(--accent); color: var(--accent-fg); cursor: pointer; text-decoration: none; line-height: 1.4;
        }
        button.secondary, .button.secondary { background: transparent; color: var(--accent); }
        button.danger { background: transparent; color: var(--error-fg); border-color: var(--error-fg); }
        button.link { background: none; border: 0; padding: 0; color: var(--muted); font-weight: 400; text-decoration: underline; }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
        .actions form { margin: 0; }
        .secret { display: block; padding: 12px 14px; background: var(--soft); border-radius: 8px; font-size: 16px; margin: 4px 0 12px; user-select: all; }
        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .muted { color: var(--muted); }
        @media (max-width: 640px) { .who { margin-left: 0; width: 100%; justify-content: space-between; } }
    </style>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="/admin">BulSU SSO <span>Admin</span></a>
        <nav class="sections" aria-label="Admin sections">
            <a href="/admin" @if (request()->is('admin')) aria-current="page" @endif>Overview</a>
            @if (auth()->user()?->hasRole('ICTO_ADMIN'))
                <a href="/admin/apps" @if (request()->is('admin/apps*')) aria-current="page" @endif>Apps</a>
            @endif
        </nav>
        <div class="who">
            <span>Signed in as <strong>{{ auth()->user()?->username }}</strong></span>
            <form method="post" action="{{ route('logout') }}">@csrf<button type="submit" class="link">Sign out</button></form>
        </div>
    </div>
</header>
<main>
    @if (session('status'))
        <p class="alert ok" role="status">{{ session('status') }}</p>
    @endif
    @yield('content')
</main>
</body>
</html>
