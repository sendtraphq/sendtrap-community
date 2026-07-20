<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Http\Resources\InboxResource;
use Sendtrap\Core\Http\Resources\MessageResource;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Support\IpAllowList;

/**
 * Community's own InboxController — authored NEW, never lifted from the
 * Cloud host (Plan 06 Phase 4b design §7.2.1, F3). The Cloud controller
 * counts inboxes via `$team->inboxes()`, and its show/settings supply
 * Team-flavored access copy plus Team-management and plan-page URL props —
 * none of which exist in Community. Here the inbox limit's count
 * basis is `$project->inboxes()->count()` (F3), and the package's
 * `access*` props (Plan 06 Phase 3 gate finding #1) carry Community's own
 * workspace-neutral copy with `accessManageUrl`/`accessManageLabel` null so
 * the package components' manage-link/upgrade affordances don't render
 * (M-3). The `max_messages` clamp carries over verbatim — it is
 * workspace-derived (`Entitlements::for($project->workspace)`), not
 * Team-derived.
 */
class InboxController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $workspace = $project->workspace;

        abort_unless($workspace !== null, 403);

        $plan = app(Entitlements::class)->for($workspace);

        abort_unless(
            $plan->within('inboxes', $project->inboxes()->count()),
            403,
            'This instance’s inbox limit has been reached.',
        );

        // New inboxes start at the configured per-inbox message cap (falls
        // back to the column default only when the instance is unlimited —
        // a configured 0 is a real, blocking cap).
        if (($cap = $plan->messagesPerInbox()) !== null) {
            $validated['max_messages'] = $cap;
        }

        $inbox = $project->inboxes()->create($validated);

        return redirect()->route('inboxes.show', $inbox);
    }

    /**
     * The inbox view: message list (server-rendered first page) + reader shell.
     */
    public function show(Request $request, Inbox $inbox): Response
    {
        $this->authorize('view', $inbox);

        $messages = $inbox->messages()
            ->orderByDesc('received_at')
            ->paginate(50);

        return Inertia::render('Inboxes/Show', [
            'inbox' => (new InboxResource($inbox->loadCount('messages')->load('shares')))->resolve(),
            'messages' => MessageResource::collection($messages),
            // §7.2/§7.2.1: no Team concept, no team-management page, no
            // upgrade page — the package MessageReader/InboxSettings render
            // this workspace-neutral copy and hide the manage link/upgrade
            // affordances since accessManageUrl/upgradeUrl are null.
            'accessTitle' => 'Workspace access',
            'accessDescription' => 'Everyone in this workspace can access this inbox. Manage members, roles and the instance IP allowlist from Settings.',
            'accessManageUrl' => null,
            'accessManageLabel' => null,
            'usage' => app(UsageMeter::class)->summary($inbox->project->workspace),
            'upgradeUrl' => null,
        ]);
    }

    public function settings(Request $request, Inbox $inbox): Response
    {
        $this->authorize('update', $inbox);

        return Inertia::render('Inboxes/Settings', [
            'inbox' => (new InboxResource($inbox->loadCount('messages')->load('shares')))->resolve(),
            'accessTitle' => 'Workspace access',
            'accessDescription' => 'Everyone in this workspace can access this inbox. Manage members, roles and the instance IP allowlist from Settings.',
            'accessManageUrl' => null,
            'accessManageLabel' => null,
        ]);
    }

    public function update(Request $request, Inbox $inbox)
    {
        $this->authorize('update', $inbox);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'max_messages' => ['required', 'integer', 'min:1', 'max:10000'],
            'auto_forward_to' => ['nullable', 'email'],
            'webhook_url' => ['nullable', 'url', function (string $attribute, $value, $fail) {
                $host = parse_url($value, PHP_URL_HOST);
                $ip = $host ? (filter_var($host, FILTER_VALIDATE_IP) ?: gethostbyname($host)) : null;
                if ($ip && IpAllowList::isReservedOrPrivate($ip)) {
                    $fail('The webhook URL must not point to a private or reserved address.');
                }
            }],
            'allowed_ips' => ['nullable', 'array'],
            'allowed_ips.*' => [self::ipRule()],
        ]);

        $validated['allowed_ips'] = IpAllowList::normalize($request->input('allowed_ips', [])) ?: null;

        // Don't let a user raise retention above the instance's per-inbox
        // cap (workspace-derived — carries over verbatim, §7.2.1). Strict
        // null check: a configured 0 still clamps.
        if (($cap = app(Entitlements::class)->for($inbox->project->workspace)->messagesPerInbox()) !== null) {
            $validated['max_messages'] = min($validated['max_messages'], $cap);
        }

        $inbox->update($validated);

        return back();
    }

    /**
     * Mark every message in the inbox as read (or unread when read=false).
     *
     * §4.4 (deliberate asymmetry): per-message mark-read is package-gated
     * on `view` (a viewer may flip one message), but mark-ALL-read is a
     * bulk state mutation across the inbox — a management action — so it
     * authorizes `update` (→ canManage) and a viewer is denied.
     */
    public function markAllRead(Request $request, Inbox $inbox)
    {
        $this->authorize('update', $inbox);

        $read = $request->boolean('read', true);
        $inbox->messages()->where('is_read', ! $read)->update(['is_read' => $read]);

        return back();
    }

    /**
     * Delete every message in the inbox.
     */
    public function clear(Request $request, Inbox $inbox)
    {
        $this->authorize('update', $inbox);

        $inbox->messages()->get()->each->delete();

        return back();
    }

    public function destroy(Request $request, Inbox $inbox)
    {
        $this->authorize('delete', $inbox);

        $inbox->messages()->get()->each->delete();
        $inbox->delete();

        return redirect()->route('dashboard');
    }

    /**
     * (Re)create a public, no-login share link for this inbox, replacing any
     * existing one. Always expires — defaults to 30 days.
     *
     * Route-level `can:manage-workspace` also guards this (§4.5/§4.8):
     * share links are durable, externally-reachable exposure, so creation
     * is manager-only at the route even before this policy check runs.
     */
    public function share(Request $request, Inbox $inbox)
    {
        $this->authorize('update', $inbox);

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $inbox->shares()->delete();

        $inbox->shares()->create([
            'expires_at' => now()->addDays($validated['days'] ?? 30),
        ]);

        return back();
    }

    /**
     * Revoke the inbox's active share link, if any.
     */
    public function revokeShare(Inbox $inbox)
    {
        $this->authorize('update', $inbox);

        $inbox->shares()->delete();

        return back();
    }
}
