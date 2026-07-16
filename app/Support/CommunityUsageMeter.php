<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Contracts\Workspace;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Message;

/**
 * Plan 06 Phase 4b design §5.3: Community's implementation of `UsageMeter`.
 * Mirrors the mechanism surface of the Cloud host's sending-limiter support
 * class (the contract docblock), workspace-keyed even though Community has
 * exactly one workspace, so the code, the backing table
 * (`workspace_send_usages`, the re-keyed parallel of the Cloud host's
 * monthly-usage table), and the `summary()` shape are identical in shape to
 * Cloud's.
 *
 * - Per-minute rate: a cache fixed-window counter keyed
 *   `send-window:{workspace_id}:{minute}` (`Cache::add` + `Cache::increment`,
 *   the same fixed-window pattern as the Cloud host's rate check).
 * - Monthly quota: `insertOrIgnore` + `increment('send_count')` on
 *   `workspace_send_usages` for the current period, the same durable-counter
 *   pattern as the Cloud host's monthly write.
 * - Forwards/month: a cache counter keyed
 *   `forward-window:{workspace_id}:{period}`, the same pattern as the Cloud
 *   host's forward counter.
 * - Recent-block memory: `Cache::put("send-blocked:{workspace_id}", ...,
 *   15min)` for `SendLimitBanner`, the same pattern as the Cloud host's
 *   block-memory write.
 */
class CommunityUsageMeter implements UsageMeter
{
    public function __construct(protected Entitlements $entitlements) {}

    /**
     * Returns null when allowed, or a reason: 'rate' | 'quota'. This exact
     * vocabulary is what `SmtpServer::handleDataLine()` maps to the wire
     * replies 452 (rate) / 550 (quota) — see the package's
     * `Contracts\UsageMeter::checkSend()` docblock.
     */
    public function checkSend(Workspace $workspace): ?string
    {
        $entitlements = $this->entitlements->for($workspace);

        $perMinute = $entitlements->sendsPerMinute();
        if ($perMinute !== null && $this->minuteCount($workspace) >= $perMinute) {
            return $this->recordBlock($workspace, 'rate');
        }

        $perMonth = $entitlements->sendsPerMonth();
        if ($perMonth !== null && $this->monthCount($workspace) >= $perMonth) {
            return $this->recordBlock($workspace, 'quota');
        }

        return null;
    }

    /**
     * Record an accepted send (increments both the per-minute window
     * counter and the monthly counter).
     */
    public function recordSend(Workspace $workspace): void
    {
        $key = $this->minuteKey($workspace);
        if (! Cache::add($key, 1, now()->addSeconds(120))) {
            Cache::increment($key);
        }

        $period = now()->format('Y-m');

        // insertOrIgnore handles the first concurrent send safely; increment
        // is an atomic SQL update for both the first and subsequent sends.
        DB::table('workspace_send_usages')->insertOrIgnore([
            'workspace_id' => $workspace->id(),
            'period' => $period,
            'send_count' => 0,
        ]);

        DB::table('workspace_send_usages')
            ->where('workspace_id', $workspace->id())
            ->where('period', $period)
            ->increment('send_count');
    }

    /**
     * `summary()`'s shape is load-bearing (§5.3) — `UsagePill` and
     * `SendLimitBanner` read exactly this shape.
     *
     * @return array{per_minute: ?int, per_month: ?int, month_usage: int, pct: int, recent_block: ?string}
     */
    public function summary(Workspace $workspace): array
    {
        $entitlements = $this->entitlements->for($workspace);
        $perMonth = $entitlements->sendsPerMonth();
        $usage = $this->monthCount($workspace);

        return [
            'per_minute' => $entitlements->sendsPerMinute(),
            'per_month' => $perMonth,
            'month_usage' => $usage,
            'pct' => $perMonth ? min(100, intdiv($usage * 100, $perMonth)) : 0,
            'recent_block' => $this->recentBlock($workspace),
        ];
    }

    /**
     * Auto-forwarding quota (per calendar month). Always active — forwarding
     * relays mail to a real third-party address, an abuse control rather
     * than a paywall gate. Null limit = unlimited; a configured 0 blocks
     * (D-17, §5.2).
     */
    public function canForward(Workspace $workspace): bool
    {
        $limit = $this->entitlements->for($workspace)->forwardsPerMonth();

        return $limit === null || $this->forwardCount($workspace) < $limit;
    }

    public function recordForward(Workspace $workspace): void
    {
        $key = $this->forwardKey($workspace);
        if (! Cache::add($key, 1, now()->addMonth())) {
            Cache::increment($key);
        }
    }

    /**
     * Sums `Message.size` + `Attachment.size` scoped to this workspace via
     * `inbox.project.workspace_id` — the workspace-keyed parallel of
     * `CloudUsageMeter::currentStorageBytes()`'s `team_id` query.
     */
    public function currentStorageBytes(Workspace $workspace): int
    {
        $messageBytes = (int) Message::whereHas(
            'inbox.project',
            fn ($query) => $query->where('workspace_id', $workspace->id())
        )->sum('size');

        $attachmentBytes = (int) Attachment::whereHas(
            'message.inbox.project',
            fn ($query) => $query->where('workspace_id', $workspace->id())
        )->sum('size');

        return $messageBytes + $attachmentBytes;
    }

    /**
     * Always false when the workspace has no storage limit.
     */
    public function wouldExceedStorage(Workspace $workspace, int $incomingBytes): bool
    {
        $limit = $this->entitlements->for($workspace)->storageBytesLimit();

        if ($limit === null) {
            return false;
        }

        return ($this->currentStorageBytes($workspace) + $incomingBytes) > $limit;
    }

    public function minuteCount(Workspace $workspace): int
    {
        return (int) Cache::get($this->minuteKey($workspace), 0);
    }

    /**
     * Monthly usage, from the durable database counter incremented in
     * recordSend() — not derived from stored Message rows, since those can
     * be deleted (retention pruning, bulk-delete), which would otherwise let
     * a workspace reclaim quota mid-month by deleting messages.
     */
    public function monthCount(Workspace $workspace): int
    {
        return (int) DB::table('workspace_send_usages')
            ->where('workspace_id', $workspace->id())
            ->where('period', now()->format('Y-m'))
            ->value('send_count');
    }

    public function forwardCount(Workspace $workspace): int
    {
        return (int) Cache::get($this->forwardKey($workspace), 0);
    }

    /**
     * Remember that a send was blocked so the web UI can warn about it
     * (`SendLimitBanner`, via `summary()['recent_block']`).
     */
    protected function recordBlock(Workspace $workspace, string $reason): string
    {
        Cache::put("send-blocked:{$workspace->id()}", $reason, now()->addMinutes(15));

        return $reason;
    }

    protected function recentBlock(Workspace $workspace): ?string
    {
        return Cache::get("send-blocked:{$workspace->id()}");
    }

    protected function minuteKey(Workspace $workspace): string
    {
        return "send-window:{$workspace->id()}:".now()->format('YmdHi');
    }

    protected function forwardKey(Workspace $workspace): string
    {
        return "forward-window:{$workspace->id()}:".now()->format('Ym');
    }
}
