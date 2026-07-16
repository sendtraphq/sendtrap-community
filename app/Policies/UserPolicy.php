<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\Workspace as WorkspaceContract;

/**
 * Plan 06 Phase 4b design §4.3: user management is owner-only and enforced
 * by a Community-side policy — the core `WorkspaceAccess` contract governs
 * email-domain resources only and has no notion of "manage users".
 * Registered via `Gate::policy(User::class, UserPolicy::class)` in
 * `CommunityServiceProvider::boot()`; carries the (slice 6) `/users*`
 * route group per the §4.8 route table.
 *
 * Last-owner protection (§3.4/§3.5's "no unrecoverable state" pattern,
 * applied here — NOT separately mandated by §4.6's text, which specifies
 * only the owner-only gate and the `usersLimit()` count basis; this is a
 * narrow, documented safety addition rather than an invented product
 * feature): every role-gated Users-page action is owner-only, but deleting
 * or demoting the *last* remaining owner would leave the instance with no
 * one able to pass any `manage-instance`/`UserPolicy` gate ever again — no
 * code path recovers from that short of a raw DB edit. `delete()` and
 * `demotable()` both deny that specific case; every other owner action is
 * untouched.
 *
 * Self-delete (slice 6; §4.6 is SILENT on this — flagged in the slice-6
 * report as a design-silent rule): the last-owner guard pattern is applied
 * VERBATIM and nothing stricter — an owner may delete their own account
 * exactly like any other, unless they are the last remaining owner (the
 * unrecoverable case). This is the semantics the slice-3 tests pinned
 * (`test_owner_can_delete_one_of_several_owners` asserts self-delete is
 * allowed with a second owner present) and the orchestrator ratified;
 * `UserController::destroy()` invalidates the session on the self path so
 * the request doesn't continue on a deleted account.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, User $target): bool
    {
        if (! $user->isOwner()) {
            return false;
        }

        return ! $this->isLastOwner($target);
    }

    /**
     * Whether `$target` is the sole remaining owner — used by `delete()`
     * above and reusable by the future Users-page controller (§11 slice 6)
     * to deny a role-change away from `owner` on the same user.
     */
    public function isLastOwner(User $target, bool $lockForUpdate = false): bool
    {
        if (! $target->isOwner()) {
            return false;
        }

        $owners = User::query()->where('role', Role::Owner->value);

        // Callers mutating the owner set (UserController::update/destroy) pass
        // lockForUpdate inside a DB transaction so this count and the mutation
        // are atomic — two concurrent demotions/deletes can't both observe >1
        // owner and leave the instance with none. Real teeth on MySQL/Postgres
        // (a row lock the second transaction blocks on); on SQLite it is a
        // no-op, but SQLite serializes write transactions so the same-file
        // case narrows to the operator-level trust boundary the class docblock
        // already documents. The authorize()-time call keeps the default (no
        // lock): that is the optimistic gate, this is the integrity backstop.
        if ($lockForUpdate) {
            // Lock the owner ROWS and count in PHP — `SELECT count(*) … FOR
            // UPDATE` is rejected by PostgreSQL (an aggregate can't take a row
            // lock), whereas `SELECT id … FOR UPDATE` is valid on both MySQL
            // and Postgres and takes exactly the locks we need.
            return $owners->lockForUpdate()->pluck('id')->count() <= 1;
        }

        return $owners->count() <= 1;
    }

    /**
     * F7 (§4.6): the users-limit count basis, `within('users',
     * User::count())`, checked *before* creating — the current live user
     * count, mirroring `within('projects', …count())` in
     * `ProjectController@store`. The full Users controller is a slice-6
     * deliverable (`Entitlements`/`CommunityEntitlements` aren't bound until
     * slice 4 either); this is the reusable check the future controller
     * calls, kept here alongside the rest of user-management authorization
     * rather than duplicated at the call site.
     *
     * The installer's first owner is exempt by construction — it is never
     * created through this check (`sendtrap:install` creates it directly),
     * so `usersLimit()` can never block bootstrapping the one required
     * owner even under a low configured limit.
     */
    public function withinUsersLimit(Entitlements $entitlements, WorkspaceContract $workspace): bool
    {
        return $entitlements->for($workspace)->within('users', User::query()->count());
    }
}
