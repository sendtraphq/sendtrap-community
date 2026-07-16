<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\CommunityEntitlements;
use App\Support\CommunityUsageMeter;
use App\Support\CommunityWorkspaceAccess;
use App\Support\CommunityWorkspaceContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Contracts\WorkspaceAccess;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Http\Middleware\AuthenticateInboxToken;
use Sendtrap\Core\Models\Workspace;

/**
 * Community's own host-requirement wiring for `sendtrap/core`
 * (`SendtrapCoreServiceProvider`'s HOST REQUIREMENTS docblock), plus the
 * contract bindings it accumulates one slice at a time. As of slice 4, all
 * four core contracts this host binds are live: `WorkspaceContext` +
 * `WorkspaceAccess` (slice 3), `Entitlements` + `UsageMeter` (slice 4, §5).
 * `LegacyOwnershipFallback` is deliberately NOT bound (§5.4) — Community
 * inherits the package's `NullLegacyOwnershipFallback` default.
 */
class CommunityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WorkspaceContext::class, CommunityWorkspaceContext::class);
        $this->app->singleton(WorkspaceAccess::class, CommunityWorkspaceAccess::class);
        $this->app->singleton(Entitlements::class, CommunityEntitlements::class);
        $this->app->singleton(UsageMeter::class, CommunityUsageMeter::class);
    }

    public function boot(): void
    {
        $this->registerApiRateLimiters();
        $this->registerWorkspaceSingletonGuard();
        $this->registerAuthorization();
    }

    /**
     * Host requirement (package docblock item 1): the `inbox-api` /
     * `inbox-api-wait` rate limiter names. The package's `routes/api.php`
     * declares `throttle:inbox-api`/`throttle:inbox-api-wait` but registers
     * neither — every host mounting those routes must, or every request
     * through them 500s with `MissingRateLimiterException`, token or no
     * token. Ceiling for an authenticated inbox comes from
     * `Entitlements::apiRequestsPerMinute()` (bound in a later slice);
     * an anonymous/unmatched request falls back to a fixed ceiling.
     */
    private function registerApiRateLimiters(): void
    {
        RateLimiter::for('inbox-api', function (Request $request) {
            $inbox = AuthenticateInboxToken::resolve($request);
            $key = $inbox ? 'inbox:'.$inbox->id : 'ip:'.$request->ip();
            $workspace = $inbox?->project?->workspace;
            $perMinute = $workspace
                ? (app(Entitlements::class)->for($workspace)->apiRequestsPerMinute() ?? 300)
                : 60;

            return Limit::perMinute($perMinute)->by($key);
        });

        RateLimiter::for('inbox-api-wait', fn (Request $request) => Limit::perMinute(15)->by(
            $request->bearerToken() ?: $request->header('X-Api-Token') ?: $request->ip()
        ));
    }

    /**
     * Second-workspace prevention (Plan 06 Phase 4b design §3.5, F5).
     * §3.4 removes the workspace lifecycle *surface* and §3.1 makes install
     * *idempotent*, but neither on its own stops a stray
     * `Workspace::factory()->create()`/`Workspace::create()` call from
     * silently making Community multi-workspace. Registered unconditionally
     * so it is live in every request, command, and test — including under
     * the installer, which never trips it: `sendtrap:install` creates the
     * first workspace only when `Workspace::query()->exists()` is false
     * (§3.1's existence guard), so the guard only ever fires on a *second*
     * creation attempt.
     *
     * Residual trust boundary (documented, not closed): a raw-SQL
     * `INSERT INTO workspaces` bypasses Eloquent events entirely and is the
     * only remaining way to create a second row. That is an operator-level
     * database action outside this application's guarantees — the same
     * class of trust boundary as any raw-SQL edit to an invariant. See
     * `App\Support\CommunityWorkspaceContext::singleton()` for how the
     * `InstanceSettings` pointer behaves if that boundary is ever crossed.
     */
    private function registerWorkspaceSingletonGuard(): void
    {
        Workspace::creating(function (Workspace $workspace): void {
            if (Workspace::query()->exists()) {
                throw new RuntimeException(
                    'Community is single-workspace: a Workspace already exists; '.
                    'creating a second is not permitted.'
                );
            }
        });
    }

    /**
     * Plan 06 Phase 4b design §4.3/§4.8 (F2, F5-adjacent gate work): the
     * instance-sensitive gates that sit *outside* the core `WorkspaceAccess`
     * contract (core has no notion of "manage users" or "manage instance
     * settings"), plus the User policy.
     *
     *  - `manage-workspace` → `WorkspaceAccess::canManage()` against the
     *    singleton workspace. Carries `projects.store`, `inboxes.share`,
     *    `inboxes.share.destroy`, `messages.share` (§4.5, §4.8) — routes
     *    that land in a later slice, but the gate itself is a slice-3
     *    deliverable so those routes have something to bind to.
     *  - `manage-instance` → owner only. Carries `settings*` (§4.6).
     *  - `UserPolicy` → owner-only CRUD (§4.3), registered against
     *    `App\Models\User` so the future `/users*` route group (§4.8)
     *    authorizes through it.
     *
     * `manage-workspace` denies (does not throw) when the workspace isn't
     * installed yet — `WorkspaceAccess::canManage()`'s `$workspace`
     * parameter is non-nullable, so `WorkspaceContext::current()` returning
     * null (pre-install) short-circuits to `false` here rather than being
     * passed in.
     */
    private function registerAuthorization(): void
    {
        Gate::policy(User::class, UserPolicy::class);

        Gate::define('manage-workspace', function (User $user): bool {
            $workspace = $this->app->make(WorkspaceContext::class)->current();

            return $workspace !== null
                && $this->app->make(WorkspaceAccess::class)->canManage($user, $workspace);
        });

        Gate::define('manage-instance', fn (User $user): bool => $user->isOwner());
    }
}
