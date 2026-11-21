<style>
    :root {
        --bg: #f3f4f6; --card: #ffffff; --fg: #1c1f24; --muted: #5b6270; --line: #d7dbe1;
        --accent: #7a1f2b; --accent-fg: #ffffff;
        --error-bg: #fbe8ea; --error-fg: #8e1b29; --info-bg: #e5eff8; --info-fg: #1f4e79;
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #111318; --card: #1b1e25; --fg: #e8eaee; --muted: #a2a9b5; --line: #343944;
            --accent: #d4707d; --accent-fg: #111318;
            --error-bg: #3a1c21; --error-fg: #f3a7b0; --info-bg: #1a2a3a; --info-fg: #a9cbea;
        }
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px;
        background: var(--bg); color: var(--fg);
        font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }
    .card {
        width: 100%; max-width: 380px; background: var(--card); border: 1px solid var(--line);
        border-radius: 12px; padding: 32px 28px;
    }
    .org { margin: 0 0 4px; font-size: 13px; letter-spacing: .06em; text-transform: uppercase; color: var(--accent); font-weight: 600; }
    h1 { margin: 0 0 8px; font-size: 26px; line-height: 1.2; }
    .lead { margin: 0 0 20px; color: var(--muted); }
    .lead strong { color: var(--fg); }
    form { display: grid; gap: 6px; margin: 0; }
    label { font-weight: 600; font-size: 14px; margin-top: 8px; }
    input {
        font: inherit; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px;
        background: var(--card); color: var(--fg);
    }
    input:focus-visible, button:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
    button {
        margin-top: 18px; font: inherit; font-weight: 600; padding: 11px 16px; border: 0; border-radius: 8px;
        background: var(--accent); color: var(--accent-fg); cursor: pointer;
    }
    button.secondary { background: transparent; color: var(--accent); border: 1px solid var(--accent); margin-top: 8px; }
    .alert { margin: 0 0 16px; padding: 10px 12px; border-radius: 8px; font-size: 14px; }
    .alert.error { background: var(--error-bg); color: var(--error-fg); }
    .alert.info { background: var(--info-bg); color: var(--info-fg); }
    .hint { margin: 20px 0 0; font-size: 13px; color: var(--muted); }
</style>
