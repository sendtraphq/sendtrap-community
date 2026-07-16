<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instance limits
    |--------------------------------------------------------------------------
    |
    | LIMIT SEMANTICS:
    |   null / key absent  = UNLIMITED
    |   0                  = BLOCKED (zero allowance) — NOT "unlimited"
    |   n > 0              = that many
    |
    | Only null/absent means unlimited; a configured 0 blocks — e.g.
    | forwards_per_month = 0 turns forwarding off. Some other sandboxes
    | document 0 as "unlimited"; this file is deliberately the opposite, so
    | never copy limit values across from elsewhere without re-checking the
    | direction — a bare port would silently invert every limit below.
    |
    | These semantics are pinned by tests. If they are ever reversed, only
    | this comment block and the direction of those tests need to change —
    | every getter below already passes the raw configured value straight
    | through unchanged.
    |
    | Every key is read by App\Support\CommunityWorkspaceEntitlements, one per
    | Sendtrap\Core\Contracts\WorkspaceEntitlements getter, and defaults to
    | null (unlimited) when its SENDTRAP_* env var is unset.
    |
    */
    'limits' => array_map(
        static fn (?string $value): ?int => $value === null ? null : (int) $value,
        [
            // Sending rate ceiling, per minute. Always active (abuse control).
            'sends_per_minute' => env('SENDTRAP_SENDS_PER_MINUTE'),

            // Sending quota, per calendar month. Always active.
            'sends_per_month' => env('SENDTRAP_SENDS_PER_MONTH'),

            // Auto-forwarding quota, per calendar month. Always active.
            'forwards_per_month' => env('SENDTRAP_FORWARDS_PER_MONTH'),

            // Per-message size cap, in bytes. Always active.
            'email_size_bytes' => env('SENDTRAP_EMAIL_SIZE_BYTES'),

            // Maximum number of projects. Advisory (feature-count) limit.
            'projects' => env('SENDTRAP_PROJECTS_LIMIT'),

            // Maximum number of inboxes. Advisory limit.
            'inboxes' => env('SENDTRAP_INBOXES_LIMIT'),

            // Maximum number of users. Advisory limit (F7 — counted against
            // the live User::count(), the installer's first owner exempt).
            'users' => env('SENDTRAP_USERS_LIMIT'),

            // Per-inbox message cap. Always active.
            'messages_per_inbox' => env('SENDTRAP_MESSAGES_PER_INBOX'),

            // Age-based message retention, in days. Always active.
            'retention_days' => env('SENDTRAP_RETENTION_DAYS'),

            // Workspace-wide storage cap, in bytes, across all messages and
            // attachments. Always active.
            'storage_bytes' => env('SENDTRAP_STORAGE_BYTES'),

            // Requests/minute ceiling for the token-authenticated inbox API.
            // Always active. Null here falls back to a fixed 300/min ceiling
            // (CommunityServiceProvider::registerApiRateLimiters()).
            'api_requests_per_minute' => env('SENDTRAP_API_REQUESTS_PER_MINUTE'),
        ]
    ),

];
