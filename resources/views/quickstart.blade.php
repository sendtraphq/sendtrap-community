<!DOCTYPE html>
{{--
    Pre-install quickstart — served at / until sendtrap:install has run,
    then the route redirects to login instead. Deliberately zero built
    assets (inline styles, no @vite): the page must render before
    `npm run build` has happened, because that's one of the steps it checks.
--}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Almost there — Sendtrap</title>
    <link rel="icon" href="/favicon.ico">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #0C1525; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
        .card { max-width: 640px; width: 100%; }
        .brand { font-family: ui-monospace, monospace; font-weight: 700; font-size: 1.4rem; color: #fff; letter-spacing: -0.02em; }
        .brand span { color: #60a5fa; }
        h1 { font-size: 1.6rem; font-weight: 800; margin-top: 1.5rem; color: #fff; letter-spacing: -0.02em; }
        .lede { margin-top: 0.75rem; color: #94a3b8; line-height: 1.6; font-size: 0.95rem; }
        ol { list-style: none; margin-top: 2rem; display: grid; gap: 0.75rem; }
        li { display: flex; gap: 0.9rem; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 0.75rem; padding: 1rem 1.1rem; }
        .mark { flex: none; width: 1.5rem; height: 1.5rem; border-radius: 999px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; margin-top: 0.1rem; }
        .done   { background: #16653433; color: #4ade80; border: 1px solid #4ade8055; }
        .todo   { background: transparent; color: #64748b; border: 1px dashed #475569; }
        code { font-family: ui-monospace, monospace; font-size: 0.85rem; color: #fbbf24; }
        .detail { margin-top: 0.3rem; color: #94a3b8; font-size: 0.82rem; line-height: 1.5; }
        .strike code { color: #64748b; text-decoration: line-through; }
        .foot { margin-top: 2rem; color: #64748b; font-size: 0.8rem; line-height: 1.6; }
        .foot a { color: #60a5fa; text-decoration: none; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">send<span>trap</span></div>
        <h1>The install is nearly done.</h1>
        <p class="lede">
            Sendtrap Community is serving this page, so PHP and your web entry point already work.
            These are the remaining steps from the README — this page updates live, refresh after each one.
        </p>
        <ol>
            @foreach ($steps as $step)
                <li>
                    @if ($step['done'] === true)
                        <span class="mark done">✓</span>
                    @else
                        <span class="mark todo">{{ $loop->iteration }}</span>
                    @endif
                    <div @class(['strike' => $step['done'] === true])>
                        <code>{{ $step['command'] }}</code>
                        <div class="detail">{{ $step['detail'] }}</div>
                    </div>
                </li>
            @endforeach
        </ol>
        <p class="foot">
            SMTP will listen on port {{ $smtpPort }}. Once installed, this page becomes your login —
            docs at <a href="https://github.com/sendtraphq/sendtrap-community" rel="noopener">github.com/sendtraphq/sendtrap-community</a>.
        </p>
    </main>
</body>
</html>
