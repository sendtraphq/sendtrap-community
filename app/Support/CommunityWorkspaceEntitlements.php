<?php

namespace App\Support;

use Sendtrap\Core\Contracts\WorkspaceEntitlements;

/**
 * Plan 06 Phase 4b design §5.1: Community's implementation of
 * `WorkspaceEntitlements`. Every getter is a one-line read of
 * `config('sendtrap-community.limits.*')` — Community has no plan or
 * subscription concept, so there is nothing to resolve beyond the config
 * file itself (contrast the Cloud host's equivalent, which wraps a
 * resolved, subscription-backed plan object).
 *
 * D-17 (§5.2): the config file already casts every configured value to
 * `?int` (null when the env var is unset). Every "always active" getter
 * below passes that value straight through unchanged, including a
 * configured `0` — the D-17 passthrough is what makes a later owner
 * reversal of the convention a comment-only change (§5.2's R2 risk note).
 */
class CommunityWorkspaceEntitlements implements WorkspaceEntitlements
{
    public function sendsPerMinute(): ?int
    {
        return config('sendtrap-community.limits.sends_per_minute');
    }

    public function sendsPerMonth(): ?int
    {
        return config('sendtrap-community.limits.sends_per_month');
    }

    public function forwardsPerMonth(): ?int
    {
        return config('sendtrap-community.limits.forwards_per_month');
    }

    public function emailSizeBytes(): ?int
    {
        return config('sendtrap-community.limits.email_size_bytes');
    }

    public function projectsLimit(): ?int
    {
        return config('sendtrap-community.limits.projects');
    }

    public function inboxesLimit(): ?int
    {
        return config('sendtrap-community.limits.inboxes');
    }

    public function usersLimit(): ?int
    {
        return config('sendtrap-community.limits.users');
    }

    public function messagesPerInbox(): ?int
    {
        return config('sendtrap-community.limits.messages_per_inbox');
    }

    public function retentionDays(): ?int
    {
        return config('sendtrap-community.limits.retention_days');
    }

    public function storageBytesLimit(): ?int
    {
        return config('sendtrap-community.limits.storage_bytes');
    }

    public function apiRequestsPerMinute(): ?int
    {
        return config('sendtrap-community.limits.api_requests_per_minute');
    }

    /**
     * Parity row 6 (§5.1): Community ships the HTML-Check API ungated —
     * always true, never config-driven.
     */
    public function hasApiAccess(): bool
    {
        return true;
    }

    /**
     * Parity row 26 (§5.1): Community's "support" is docs/issues, not an
     * in-app form — always false.
     */
    public function hasSupport(): bool
    {
        return false;
    }

    /**
     * Parity row 6 (§5.1): ungated, same as hasApiAccess().
     */
    public function hasHtmlCheckApi(): bool
    {
        return true;
    }

    /**
     * Advisory feature-count limits only (§5.1) — 'projects', 'inboxes',
     * 'users'. Anything else (including D-19's deliberately-absent
     * 'forward_recipients') has no configured limit and is always within.
     */
    public function within(string $name, int $current): bool
    {
        $limit = match ($name) {
            'projects' => $this->projectsLimit(),
            'inboxes' => $this->inboxesLimit(),
            'users' => $this->usersLimit(),
            default => null,
        };

        return $limit === null || $current < $limit;
    }
}
