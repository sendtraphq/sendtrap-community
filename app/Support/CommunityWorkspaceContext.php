<?php

namespace App\Support;

use RuntimeException;
use Sendtrap\Core\Contracts\Workspace as WorkspaceContract;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;

/**
 * Production binding of `Sendtrap\Core\Contracts\WorkspaceContext` (Plan 06
 * Phase 4b design §3.2). Community has exactly one Workspace, installed by
 * `sendtrap:install` — this always resolves it, ignoring any caller/session
 * state (there is nothing to disambiguate).
 *
 * Bound as a container singleton (`CommunityServiceProvider::register()`),
 * so `singleton()` below runs at most once per request/command — the
 * instance-level memoisation exists for the (rare) case something resolves
 * a second instance out of the container directly.
 */
final class CommunityWorkspaceContext implements WorkspaceContext
{
    private bool $resolved = false;

    private ?Workspace $workspace = null;

    public function current(): ?WorkspaceContract
    {
        return $this->singleton();
    }

    /**
     * NOT a blind return-singleton: still resolves through the
     * inbox -> project -> workspace chain, so a request bearing a token for
     * an inbox that doesn't exist yields null (correct 404/limiter
     * fallback), not the singleton masking a missing inbox (§3.2).
     */
    public function forInboxId(int $inboxId): ?WorkspaceContract
    {
        return Inbox::query()->with('project.workspace')->find($inboxId)?->project?->workspace;
    }

    /**
     * Yields the one workspace — used by host-wide maintenance sweeps
     * (e.g. `PruneMessages`) that visit every workspace in turn.
     *
     * @return iterable<WorkspaceContract>
     */
    public function all(): iterable
    {
        yield $this->singleton();
    }

    /**
     * Resolve + memoise the singleton workspace for this instance's
     * lifetime (§3.3).
     *
     * Resolution order:
     *  1. `InstanceSettings::get('workspace_id')` — the fast pointer path.
     *  2. If the pointer is unset, or stale (points at a row that no
     *     longer exists), fall back to counting `workspaces` rows — the
     *     table, not the pointer, is the source of truth (§3.3, §3.5):
     *       - zero rows: not installed yet. Never fabricate a workspace at
     *         request time; callers abort/redirect to "run the installer".
     *       - exactly one row: self-heal by re-persisting the pointer from
     *         that row.
     *       - more than one row: the `Workspace::creating` guard
     *         (`CommunityServiceProvider::boot()`, §3.5) makes this
     *         impossible via Eloquent. The only way here is a raw-SQL
     *         INSERT bypassing Eloquent events entirely — an
     *         operator-level DB action outside the application's
     *         guarantees (§3.5's documented residual trust boundary).
     *         Silently picking a row (e.g. the first) would mask that
     *         corruption non-deterministically; instead this fails loudly
     *         and deterministically every time, so the ambiguity must be
     *         resolved in the database before Community proceeds.
     */
    private function singleton(): ?Workspace
    {
        if ($this->resolved) {
            return $this->workspace;
        }

        $pointerId = InstanceSettings::get('workspace_id');

        if ($pointerId !== null) {
            $workspace = Workspace::query()->find($pointerId);

            if ($workspace !== null) {
                $this->resolved = true;

                return $this->workspace = $workspace;
            }

            // Pointer is stale — fall through to the row-count self-heal.
        }

        $count = Workspace::query()->count();

        if ($count === 0) {
            $this->resolved = true;

            return $this->workspace = null;
        }

        if ($count > 1) {
            // Deliberately do NOT set $this->resolved here: this is an
            // ambiguous, corrupt state, not a value to memoise. Every call
            // re-checks and re-throws for as long as the ambiguity exists
            // in the database — a caller cannot get a stale "it resolved
            // fine last time" result by calling current()/all() again.
            throw new RuntimeException(
                "Community is single-workspace, but {$count} `workspaces` rows exist ".
                'while the InstanceSettings `workspace_id` pointer is unset or stale. '.
                'This can only happen via a raw-SQL insert bypassing the '.
                'Workspace::creating guard (Plan 06 Phase 4b design §3.5) — an '.
                'operator-level database action outside this application\'s '.
                'guarantees. Resolve the ambiguity directly in the database '.
                '(remove the extra row(s)) before Community can continue.'
            );
        }

        $workspace = Workspace::query()->firstOrFail();

        InstanceSettings::put('workspace_id', $workspace->id);

        $this->resolved = true;

        return $this->workspace = $workspace;
    }
}
