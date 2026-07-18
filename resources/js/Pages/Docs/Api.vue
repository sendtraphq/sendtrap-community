<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Community API docs page (Plan 06 Phase 4b design §8 row 28, Adapted;
 * §11 slice 7). Community-authored — Cloud's /docs/api is a marketing
 * Blade and is not extracted. This page documents the CORE inbox API the
 * package mounts (routes/api.php: native v1 + the Mailtrap-compatible
 * aliases), with every Cloud-plan note removed:
 *
 *  - rate limits come from instance config (default 300/min), not plans;
 *  - the HTML-Check endpoint and assert's min_compatibility_score are
 *    available on every install (CommunityWorkspaceEntitlements ships
 *    them ungated — §5.1, parity row 6), so no tier badges;
 *  - examples use a relative /api/v1 base (the instance's own origin).
 */

const endpoints = [
    { method: 'GET', path: '/inbox', desc: 'Details about the authenticated inbox' },
    { method: 'GET', path: '/messages', desc: 'List messages (paginated, searchable, filterable, optionally blocking)' },
    { method: 'POST', path: '/expect', desc: 'Wait, match, assert and diagnose in one request — the recommended testing endpoint' },
    { method: 'POST', path: '/assert', desc: 'Block until a matching message arrives (or timeout), return pass/fail' },
    { method: 'POST', path: '/messages/{id}/extract', desc: 'Pull verification codes, links, addresses and attachments out of a message' },
    { method: 'GET', path: '/messages/{id}', desc: 'Full message detail — headers, HTML, text, links, lint checks' },
    { method: 'GET', path: '/messages/{id}/raw', desc: 'Raw RFC 822 source' },
    { method: 'GET', path: '/messages/{id}/html', desc: 'Rendered HTML body' },
    { method: 'GET', path: '/messages/{id}/compatibility', desc: 'HTML Check — email-client HTML/CSS support breakdown' },
    { method: 'GET', path: '/messages/{id}/attachments/{attachment}', desc: 'Download an attachment' },
    { method: 'PATCH', path: '/messages/{id}', desc: 'Mark a message read / unread' },
    { method: 'DELETE', path: '/messages/{id}', desc: 'Delete a single message' },
    { method: 'DELETE', path: '/messages', desc: 'Delete every message in the inbox' },
];

const listParams = [
    { param: 'search', desc: 'Matches subject, from address, from name' },
    { param: 'to', desc: "Recipient contains this address — checked against the To/Cc headers and the SMTP envelope, so BCC'd recipients match too" },
    { param: 'test_id', desc: 'Exact match against the X-Sendtrap-Test-Id header' },
    { param: 'wait', desc: 'Seconds to block if no message matches yet, up to 30' },
    { param: 'per_page', desc: 'Default 50' },
];

const errors = [
    { status: '401', desc: 'Missing or invalid token' },
    { status: '403', desc: "Request IP not on the inbox's, project's or instance's allowlist (if configured)" },
    { status: '404', desc: 'Message/attachment not found, or belongs to a different inbox' },
    { status: '429', desc: 'Rate limit exceeded — see rate limits above' },
];

const mailtrapRows = [
    { method: 'GET', path: '/sandboxes/{s}/messages', desc: 'Get Messages (search, page, last_id)' },
    { method: 'GET', path: '/sandboxes/{s}/messages/{id}', desc: 'Show Email Message' },
    { method: 'PATCH', path: '/sandboxes/{s}/messages/{id}', desc: 'Update Message — {"message":{"is_read":true}}' },
    { method: 'DELETE', path: '/sandboxes/{s}/messages/{id}', desc: 'Delete Message' },
    { method: 'GET', path: '.../messages/{id}/body.txt', desc: 'Get Text Message Body' },
    { method: 'GET', path: '.../messages/{id}/body.html', desc: 'Get Formatted HTML Message' },
    { method: 'GET', path: '.../messages/{id}/body.htmlsource', desc: 'Get HTML Message Source' },
    { method: 'GET', path: '.../messages/{id}/body.raw', desc: 'Get Raw Message Body' },
    { method: 'GET', path: '.../messages/{id}/body.eml', desc: 'Get Message as EML' },
    { method: 'GET', path: '.../messages/{id}/mail_headers', desc: 'Get Mail Headers' },
    { method: 'GET', path: '.../messages/{id}/attachments', desc: 'Get Attachments' },
    { method: 'GET', path: '.../attachments/{id}', desc: 'Get Single Attachment' },
    { method: 'GET', path: '.../attachments/{id}/download', desc: 'Download attachment bytes' },
    { method: 'PATCH', path: '/sandboxes/{s}/clean', desc: 'Clean Sandbox (delete all messages)' },
    { method: 'PATCH', path: '/sandboxes/{s}/all_read', desc: 'Mark All as Read' },
];

const methodClass = (method) => ({
    GET: 'text-emerald-600',
    POST: 'text-blue-600',
    PATCH: 'text-amber-600',
    DELETE: 'text-red-600',
}[method]);
</script>

<template>
    <AppLayout title="API docs">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-12">
            <header>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">API reference</h1>
                <p class="mt-3 text-base leading-relaxed text-slate-600">
                    A small, token-authenticated REST API for reading and clearing test inboxes from your
                    test runner or CI pipeline. No SDK required — plain HTTP in, JSON out.
                </p>
                <p class="mt-2 font-mono text-xs text-slate-500">
                    Base URL: <code class="rounded bg-slate-100 px-1.5 py-0.5">https://&lt;your-instance&gt;/api/v1</code>
                </p>
                <p class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-semibold">
                    <a href="/docs/api/reference" class="rounded-lg bg-slate-900 px-3 py-1.5 text-white transition hover:bg-slate-700">Interactive reference ↗</a>
                    <a href="/docs/api/openapi.yaml" class="text-slate-500 transition hover:text-slate-900">OpenAPI 3.1 (YAML)</a>
                    <a href="/docs/api/openapi.json" class="text-slate-500 transition hover:text-slate-900">JSON</a>
                    <a href="/docs/api/sendtrap.postman_collection.json" class="text-slate-500 transition hover:text-slate-900">Postman collection</a>
                </p>
            </header>

            <section id="auth">
                <h2 class="text-xl font-bold tracking-tight text-slate-900">Authentication</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    Every inbox has its own API token — find it on the inbox's <strong>Settings</strong> page,
                    under <span class="font-mono text-xs">Integration</span>. Send it as a bearer token on every
                    request. There's no separate account-level key and no OAuth flow: one token, scoped to one
                    inbox.
                </p>
                <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-900 p-4 text-xs leading-relaxed text-slate-200"><code>curl https://&lt;your-instance&gt;/api/v1/inbox \
  -H "Authorization: Bearer &lt;your-inbox-token&gt;"</code></pre>
                <p class="mt-3 text-xs leading-relaxed text-slate-500">
                    If your HTTP client can't set an <span class="font-mono">Authorization</span> header, send the
                    token as an <span class="font-mono">X-Api-Token</span> header instead. Requests without either
                    return <span class="font-mono">401</span>.
                </p>
            </section>

            <section id="rate-limits">
                <h2 class="text-xl font-bold tracking-tight text-slate-900">Rate limits</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    Per token. The ceiling is instance configuration
                    (<span class="font-mono text-xs">SENDTRAP_API_REQUESTS_PER_MINUTE</span>, default
                    <span class="font-mono text-xs">300</span> requests/minute) — ask the instance owner if you
                    need it changed. Blocking <span class="font-mono text-xs">wait=</span> requests and
                    <span class="font-mono text-xs">POST /assert</span> share a tighter fixed limit of
                    <span class="font-mono text-xs">15/minute</span>, since each one can hold a connection open
                    for its full timeout. Exceeding either returns
                    <span class="font-mono text-xs">429 Too Many Requests</span>.
                </p>
            </section>

            <section id="endpoints">
                <h2 class="text-xl font-bold tracking-tight text-slate-900">Endpoints</h2>
                <div class="mt-4 overflow-x-auto rounded-xl ring-1 ring-slate-200 bg-white/70">
                    <table class="w-full min-w-[560px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr><th class="px-4 py-3">Method</th><th class="px-4 py-3">Path</th><th class="px-4 py-3">Description</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-mono text-[13px]">
                            <tr v-for="row in endpoints" :key="row.method + row.path">
                                <td class="px-4 py-2.5" :class="methodClass(row.method)">{{ row.method }}</td>
                                <td class="px-4 py-2.5">{{ row.path }}</td>
                                <td class="px-4 py-2.5 font-sans text-slate-600">{{ row.desc }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="list-messages">
                <h2 class="text-xl font-bold tracking-tight text-slate-900">List messages</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    Returns the inbox's messages, newest first, with standard Laravel pagination —
                    <span class="font-mono text-xs">data</span> for the page of results,
                    <span class="font-mono text-xs">meta</span> for page/total info.
                </p>
                <div class="mt-4 overflow-x-auto rounded-xl ring-1 ring-slate-200 bg-white/70">
                    <table class="w-full min-w-[480px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr><th class="px-4 py-3">Param</th><th class="px-4 py-3">Description</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in listParams" :key="row.param">
                                <td class="px-4 py-2.5 font-mono text-[13px] text-slate-800">{{ row.param }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ row.desc }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-4 text-xs leading-relaxed text-slate-500">
                    Tag outgoing mail with an <span class="font-mono">X-Sendtrap-Test-Id</span> header and filter
                    by <span class="font-mono">test_id</span> so a test can find its message without needing a
                    unique recipient address. <span class="font-mono">envelope_to</span> in the response is the
                    SMTP <span class="font-mono">RCPT TO</span> list, captured independently of the To/Cc headers
                    — the only reliable way to see a BCC'd recipient.
                </p>
            </section>

            <section id="wait-assert">
                <h2 class="text-xl font-bold tracking-tight text-slate-900">Wait &amp; assert</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    Mail arrives asynchronously. Instead of sleep-polling, add
                    <span class="font-mono text-xs">wait=&lt;seconds&gt;</span> to
                    <span class="font-mono text-xs">GET /messages</span> — if nothing matches yet the request
                    blocks until a match arrives or the timeout hits, capped at 30s.
                    <span class="font-mono text-xs">POST /assert</span> goes further: give it a condition and it
                    always returns <span class="font-mono text-xs">200</span> with a pass/fail flag in the body —
                    an unmatched assertion is an expected test outcome, not an HTTP error.
                </p>
                <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-900 p-4 text-xs leading-relaxed text-slate-200"><code>curl -X POST https://&lt;your-instance&gt;/api/v1/assert \
  -H "Authorization: Bearer &lt;token&gt;" \
  -H "Content-Type: application/json" \
  -d '{"test_id": "ci-run-482", "subject_contains": "Welcome", "timeout": 10}'

# =&gt; { "matched": true, "message": { "id": 33, "subject": "Welcome ...", ... } }</code></pre>
                <p class="mt-3 text-xs leading-relaxed text-slate-500">
                    <span class="font-mono">/assert</span> accepts <span class="font-mono">to</span>,
                    <span class="font-mono">test_id</span>, <span class="font-mono">subject_contains</span>,
                    <span class="font-mono">timeout</span> (seconds, capped at 30; omit or 0 for an instant,
                    non-blocking check) and <span class="font-mono">min_compatibility_score</span> (0–100) — the
                    matched message's <a href="#html-check" class="font-semibold text-brand-600">HTML Check</a>
                    compatibility ratio must be at or above this value, handy for gating a CI run on
                    email-client compatibility, not just on the message arriving.
                </p>
                <p class="mt-4 text-sm leading-relaxed text-slate-600">
                    <span class="font-mono text-xs">POST /expect</span> is the richer successor: separate
                    <em>match</em> conditions (which message are we waiting for?) from <em>assert</em> conditions
                    (is its content right?) across subject, recipients, envelope, bodies, headers, links,
                    attachments and quality checks — and a miss tells you whether nothing arrived, mail arrived
                    but didn't match, or the right mail arrived with the wrong content. An optional
                    <span class="font-mono text-xs">extract</span> object additionally pulls named values out of
                    the matched message in the same request — see
                    <a href="#extract" class="font-semibold text-brand-600">Extract values</a>. Full request
                    schema and field/operator matrix in the
                    <a href="/docs/api/reference" class="font-semibold text-brand-600">interactive reference</a>.
                </p>
            </section>

            <section id="extract">
                <h2 class="text-xl font-bold tracking-tight text-slate-900">Extract values</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    Signup-verification, password-reset and magic-link tests all end the same way: fishing a
                    code or link out of an email. Named extractors do that server-side — no MIME parsing or
                    HTML regexes in your test. Add an <span class="font-mono text-xs">extract</span> object to
                    <span class="font-mono text-xs">POST /expect</span> and matching + extraction happen
                    atomically in one request, or run the same extractors against a message you already have
                    with <span class="font-mono text-xs">POST /messages/{id}/extract</span>.
                </p>
                <pre class="mt-4 overflow-x-auto rounded-xl bg-slate-900 p-4 text-xs leading-relaxed text-slate-200"><code>curl -X POST https://&lt;your-instance&gt;/api/v1/expect \
  -H "Authorization: Bearer &lt;token&gt;" \
  -H "Content-Type: application/json" \
  -d '{
    "match":   [{"field": "to", "op": "contains", "value": "alice@example.com"},
                {"field": "subject", "op": "contains", "value": "Verify"}],
    "extract": {
      "code":        {"type": "code", "near": "verification code"},
      "verify_link": {"type": "link", "path_prefix": "/verify"}
    },
    "wait": {"timeout_ms": 10000},
    "mode": "strict"
  }'

# =&gt; { "matched": true, "status": "matched",
#      "extract": { "code":        { "found": true, "value": "482913", ... },
#                   "verify_link": { "found": true, "value": { "url": "https://app.example.com/verify?token=abc123",
#                                                              "text": "Verify my account" } } } }</code></pre>
                <p class="mt-3 text-xs leading-relaxed text-slate-500">
                    Five extractor types: <span class="font-mono">code</span> (verification-code helper —
                    standalone token of a configured length/charset in the visible text; with
                    <span class="font-mono">near</span>, the token closest to the anchor phrase wins),
                    <span class="font-mono">link</span> (select by <span class="font-mono">url</span>,
                    <span class="font-mono">host</span>, <span class="font-mono">path_prefix</span>,
                    <span class="font-mono">query_param</span>, visible <span class="font-mono">text_contains</span>
                    or a <span class="font-mono">matches</span> regex — links are returned, never fetched),
                    <span class="font-mono">regex</span> (bounded capture from text, HTML source, subject or a
                    named header), <span class="font-mono">address</span> (from/to/cc or the SMTP envelope —
                    <span class="font-mono">envelope_to</span> catches BCC-only recipients) and
                    <span class="font-mono">attachment</span> (metadata plus the authenticated download URL).
                </p>
                <p class="mt-3 text-xs leading-relaxed text-slate-500">
                    Results are explicit: <span class="font-mono">found</span> /
                    <span class="font-mono">not_found</span> / <span class="font-mono">ambiguous</span> — several
                    distinct matches are never guessed among; pass <span class="font-mono">select</span>
                    (<span class="font-mono">first | last | all</span>) to choose. Inside
                    <span class="font-mono">/expect</span>, a missing non-optional value keeps the wait polling
                    and reports <span class="font-mono">status: extraction_failed</span> (a
                    <span class="font-mono">422</span> in strict mode). Caps: 10 extractors per request,
                    256-byte server-delimited regexes. Full option matrix in the
                    <a href="/docs/api/reference" class="font-semibold text-brand-600">interactive reference</a>.
                </p>
            </section>

            <section id="get-message">
                <h2 class="text-xl font-bold tracking-tight text-slate-900">Get a message</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    Full detail for one message — parsed HTML and text bodies, all headers, envelope
                    from/to, extracted <span class="font-mono text-xs">links</span>, a
                    <span class="font-mono text-xs">checks</span> lint report (missing text part, oversized HTML,
                    List-Unsubscribe, From present), attachment metadata, and ready-to-fetch
                    <span class="font-mono text-xs">urls.raw</span> / <span class="font-mono text-xs">urls.html</span>
                    links.
                </p>
                <p class="mt-3 text-xs leading-relaxed text-slate-500">
                    Messages belonging to a different inbox than the one your token authenticates always return
                    <span class="font-mono">404</span> — never a 403, so you can't probe for the existence of IDs
                    outside your inbox.
                </p>
            </section>

            <section id="html-check">
                <h2 class="text-xl font-bold tracking-tight text-slate-900">HTML Check</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    <span class="font-mono text-xs">GET /messages/{id}/compatibility</span> checks a message's
                    HTML/CSS against <a href="https://www.caniemail.com" class="font-semibold text-brand-600" target="_blank" rel="noopener">caniemail.com</a>'s
                    email-client feature-support data and flags anything unsupported or partially supported, and
                    in which clients. Computed on first request and cached. Available on every install — the
                    dataset ships with the instance and works offline; refresh it any time with
                    <span class="font-mono text-xs">php artisan htmlcheck:sync-data</span>.
                </p>
                <p class="mt-3 text-xs leading-relaxed text-slate-500">
                    <span class="font-mono">compatibility_ratio</span> is the percentage of distinct HTML/CSS
                    features detected in the message that are fully supported across a fixed reference set of
                    major clients, equally weighted. Treat it as a rough CI-gating filter, not a precise
                    "% of your subscribers will see this correctly" figure.
                </p>
            </section>

            <section id="attachments">
                <h2 class="text-xl font-bold tracking-tight text-slate-900">Attachments, mark read, delete</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    Each attachment on a message detail response includes its own bearer-token-authenticated
                    <span class="font-mono text-xs">url</span> plus a sha256
                    <span class="font-mono text-xs">checksum</span> and
                    <span class="font-mono text-xs">content_type</span>, so you can assert an attachment is
                    present and correct without downloading it.
                    <span class="font-mono text-xs">PATCH /messages/{id}</span> with an
                    <span class="font-mono text-xs">is_read</span> boolean marks a message read/unread.
                    <span class="font-mono text-xs">DELETE /messages/{id}</span> deletes one message;
                    <span class="font-mono text-xs">DELETE /messages</span> clears the whole inbox, or — with any
                    of the list filters (<span class="font-mono text-xs">test_id</span>,
                    <span class="font-mono text-xs">to</span>, <span class="font-mono text-xs">search</span>,
                    <span class="font-mono text-xs">subject_contains</span>) — deletes only the matching
                    messages, so a test run sharing an inbox cleans up its own mail and leaves the rest alone.
                </p>
            </section>

            <section id="errors">
                <h2 class="text-xl font-bold tracking-tight text-slate-900">Errors</h2>
                <div class="mt-4 overflow-x-auto rounded-xl ring-1 ring-slate-200 bg-white/70">
                    <table class="w-full min-w-[420px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr><th class="px-4 py-3">Status</th><th class="px-4 py-3">Meaning</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in errors" :key="row.status">
                                <td class="px-4 py-2.5 font-mono text-[13px] text-slate-800">{{ row.status }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ row.desc }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="mailtrap">
                <h2 class="text-xl font-bold tracking-tight text-slate-900">Coming from Mailtrap</h2>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">
                    Migrating a Mailtrap Email Sandbox test helper? Every endpoint you're already calling has a
                    compatible alias under
                    <span class="font-mono text-xs">/api/sandboxes/{sandbox}/…</span> — swap the base URL and
                    token and it should just work. The <span class="font-mono text-xs">{sandbox}</span> segment is
                    accepted but not checked: your bearer token already scopes every request to one inbox, so
                    whatever sandbox ID your old script has hardcoded is fine to leave in place.
                </p>
                <div class="mt-4 overflow-x-auto rounded-xl ring-1 ring-slate-200 bg-white/70">
                    <table class="w-full min-w-[560px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr><th class="px-4 py-3">Method</th><th class="px-4 py-3">Path</th><th class="px-4 py-3">Mailtrap equivalent</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-mono text-[13px]">
                            <tr v-for="row in mailtrapRows" :key="row.method + row.path">
                                <td class="px-4 py-2.5" :class="methodClass(row.method)">{{ row.method }}</td>
                                <td class="px-4 py-2.5">{{ row.path }}</td>
                                <td class="px-4 py-2.5 font-sans text-slate-600">{{ row.desc }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs leading-relaxed text-slate-500">
                    <strong class="text-slate-700">Not implemented</strong> — these compat endpoints have no
                    equivalent in Sendtrap's model, so they aren't faked: message templates
                    (template_id/template_variables), Mailtrap's own HTML analysis and spam/blacklist report
                    shapes (use the native /messages/{id}/compatibility and /messages/{id}/spam surfaces
                    instead), POP3 access, and account-level sandbox management (create/list/delete) — a token
                    is already scoped to one inbox, so there's nothing to list or switch between.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
