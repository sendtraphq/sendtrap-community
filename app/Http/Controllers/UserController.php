<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\WorkspaceContext;

/**
 * Owner-only user management (Plan 06 Phase 4b design §4.6, §4.8) —
 * Community-authored; there is no package or Cloud equivalent to lift
 * (Cloud manages people through Jetstream Teams, which Community does not
 * install). Every action authorizes through `UserPolicy` (owner-only), the
 * §4.8 gate carrier for `users.*`.
 *
 * Provisioning model (§4.6): users are created BY an owner — this is an
 * owner action, not public registration (registration is disabled, §2.3).
 * Created users are therefore email-verified at creation time: the owner
 * vouches for the address, and no verification-mail flow exists in this
 * app (the domain routes sit behind plain `auth`, §4.8). §4.6 offers
 * "password set or invite-by-reset"; this implements the password-set arm
 * (the owner hands the credentials over) — the standard password-reset
 * flow remains available to the new user afterwards.
 *
 * F7 (§4.6): `store()` checks `within('users', User::count())` BEFORE
 * creating — the current live user count — via the policy's
 * `withinUsersLimit()` helper. The installer's first owner is exempt by
 * construction (`sendtrap:install` creates it directly, never through
 * here).
 *
 * Last-owner guard (slice-3 addition, orchestrator-ratified; §4.6 is
 * silent): `UserPolicy::delete()` denies deleting the last owner, and
 * `update()` below denies the equivalent hole §4.6's "change role" would
 * otherwise open: demoting the last remaining owner away from `owner`,
 * which would leave no user able to pass any owner gate ever again.
 * Self-delete (also design-silent — flagged) follows the same pattern and
 * nothing stricter: allowed unless it would remove the last owner, with
 * the session invalidated below so the request doesn't continue on a
 * deleted account.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $workspace = app(WorkspaceContext::class)->current();

        return Inertia::render('Users/Index', [
            'users' => User::query()
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->value,
                    'created_at' => $user->created_at?->toDateString(),
                ]),
            'roles' => array_map(fn (Role $role) => $role->value, Role::cases()),
            // Read-only context for the page header: the configured user
            // ceiling (null = unlimited, §5/D-17).
            'usersLimit' => $workspace
                ? app(Entitlements::class)->for($workspace)->usersLimit()
                : null,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $workspace = app(WorkspaceContext::class)->current();

        abort_unless($workspace !== null, 403);

        // F7 (§4.6): limit check BEFORE create, against the live count —
        // mirrors the `within('projects', …count())` pattern (and 403
        // shape) of ProjectController@store.
        abort_unless(
            app(UserPolicy::class)->withinUsersLimit(app(Entitlements::class), $workspace),
            403,
            'This instance’s user limit has been reached.',
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::default()],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        // `role` and `email_verified_at` are deliberately NOT fillable
        // (mass-assignment safety — see the User model docblock), so both
        // are explicit attribute assignments, exactly like the installer's
        // first owner (§3.1).
        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
        $user->email_verified_at = now();
        $user->role = Role::from($validated['role']);
        $user->save();

        return back();
    }

    /**
     * Change a user's role (§4.6 "change role" — the only mutable field
     * this page manages; name/email/password stay self-service, §4.3).
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        $role = Role::from($validated['role']);

        // Last-owner guard, demotion arm (design-silent — flagged): the same
        // rule UserPolicy::delete() applies, closing the equivalent hole via
        // role change. Re-checked under a row lock inside a transaction so it
        // is atomic with the save — the authorize()-time policy check is the
        // optimistic gate; this is the concurrency-safe backstop.
        DB::transaction(function () use ($user, $role) {
            abort_if(
                $role !== Role::Owner && app(UserPolicy::class)->isLastOwner($user, lockForUpdate: true),
                403,
                'The last remaining owner cannot be demoted.',
            );

            $user->role = $role;
            $user->save();
        });

        return back();
    }

    public function destroy(Request $request, User $user)
    {
        // UserPolicy::delete() carries owner-only + the last-owner guard
        // (self-delete included: allowed unless the target is the last
        // owner — the slice-3-pinned semantics).
        $this->authorize('delete', $user);

        $self = $request->user()->is($user);

        // Re-check the last-owner guard under a row lock inside a transaction,
        // atomic with the delete: authorize()'s policy check can pass on a
        // not-last owner that a concurrent request demotes/deletes into being
        // the last one between the gate and here.
        DB::transaction(function () use ($user) {
            abort_if(
                app(UserPolicy::class)->isLastOwner($user, lockForUpdate: true),
                403,
                'The last remaining owner cannot be deleted.',
            );

            $user->delete();
        });

        if ($self) {
            auth()->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return back();
    }
}
