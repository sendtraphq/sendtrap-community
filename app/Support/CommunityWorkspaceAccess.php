<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use Sendtrap\Core\Contracts\Workspace as WorkspaceContract;
use Sendtrap\Core\Contracts\WorkspaceAccess;

/**
 * Production binding of `Sendtrap\Core\Contracts\WorkspaceAccess` (Plan 06
 * Phase 4b design §4.2). Community's roles are global (per-instance), not
 * per-workspace membership — there is exactly one workspace — so this
 * reduces to a pure role check; `$workspace` is accepted anyway to keep the
 * contract shape identical to Cloud's `CloudWorkspaceAccess` (line-for-line
 * comparable, per the design).
 *
 * The three core policies (Project/Inbox/Message) delegate their `view`
 * ability to `canView()` and their `update`/`delete` abilities to
 * `canManage()` (see `src/Policies/*Policy.php` in the package) — so this
 * class alone is what makes Community's owner/member/viewer split live for
 * every email-domain resource.
 *
 * Null-safety: like the design mandates for `CloudWorkspaceAccess`, this
 * returns `false`, never throws, when `$user` has no resolvable role — an
 * authorization check that 500s risks becoming an accidental allow under a
 * catch-all.
 */
final class CommunityWorkspaceAccess implements WorkspaceAccess
{
    /**
     * Any authenticated role — owner, member, and viewer — may view.
     */
    public function canView(object $user, WorkspaceContract $workspace): bool
    {
        return in_array($this->role($user), Role::viewers(), true);
    }

    /**
     * Only owner and member may manage email-domain resources. Viewer is
     * denied.
     */
    public function canManage(object $user, WorkspaceContract $workspace): bool
    {
        return in_array($this->role($user), Role::managers(), true);
    }

    private function role(object $user): ?Role
    {
        if (! $user instanceof User) {
            return null;
        }

        return $user->role;
    }
}
