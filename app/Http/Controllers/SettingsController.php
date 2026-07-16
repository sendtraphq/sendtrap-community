<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Support\IpAllowList;

/**
 * Owner-only instance settings (Plan 06 Phase 4b design §4.6, §4.8, §8 row
 * 10). Both routes sit behind the route-level `can:manage-instance`
 * middleware — the §4.8 gate carrier for `settings*` (owner-only,
 * `Gate::define('manage-instance')` in CommunityServiceProvider).
 *
 * Surface, exactly per §4.6:
 *  - workspace name;
 *  - the INSTANCE IP allowlist → `workspace.allowed_ips` (§8 row 10: the
 *    Cloud account-tier allowlist becomes the singleton workspace's column;
 *    core enforcement already reads it — `Inbox::effectiveAllowedIps()`'s
 *    workspace tier — for both SMTP AUTH and inbox-token API requests).
 *    Validation/normalisation mirrors the Cloud TeamAccessController
 *    pattern the design cites: per-rule `ipRule()` (single IP or CIDR,
 *    v4/v6) + `IpAllowList::normalize()` with empty → null;
 *  - a READ-ONLY display of the active local limits from
 *    `config('sendtrap-community.limits.*')` — limits are config, not DB
 *    (§5); the page shows them, install/env changes them.
 */
class SettingsController extends Controller
{
    public function show(Request $request): Response
    {
        $workspace = app(WorkspaceContext::class)->current();

        abort_unless($workspace !== null, 403);

        return Inertia::render('Settings/Index', [
            'workspace' => [
                'name' => $workspace->name,
                'allowed_ips' => $workspace->allowed_ips ?? [],
            ],
            // Read-only (§4.6): null = unlimited, 0 = blocked (D-17).
            'limits' => config('sendtrap-community.limits'),
        ]);
    }

    public function update(Request $request)
    {
        $workspace = app(WorkspaceContext::class)->current();

        abort_unless($workspace !== null, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'allowed_ips' => ['nullable', 'array'],
            'allowed_ips.*' => [self::ipRule()],
        ]);

        $validated['allowed_ips'] = IpAllowList::normalize($request->input('allowed_ips', [])) ?: null;

        $workspace->update($validated);

        return back();
    }
}
