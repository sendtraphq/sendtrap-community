<?php

namespace App\Support;

use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\Workspace;
use Sendtrap\Core\Contracts\WorkspaceEntitlements;

/**
 * Plan 06 Phase 4b design §5.1: Community's implementation of
 * `Entitlements`. The Cloud host's equivalent unwraps the workspace's
 * paid subscription and resolves a per-tenant, Stripe-backed plan;
 * Community's limits are local instance config, not per-tenant, so there is
 * nothing to resolve from `$workspace` itself: every workspace (there is
 * exactly one, §3) gets the same `CommunityWorkspaceEntitlements` reading
 * `config('sendtrap-community.limits.*')`.
 */
class CommunityEntitlements implements Entitlements
{
    public function for(Workspace $workspace): WorkspaceEntitlements
    {
        return new CommunityWorkspaceEntitlements;
    }
}
