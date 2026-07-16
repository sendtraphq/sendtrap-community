<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\CommunityWorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\Workspace as WorkspaceContract;
use Sendtrap\Core\Contracts\WorkspaceAccess;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Contracts\WorkspaceEntitlements;
use Sendtrap\Core\Http\Resources\InboxResource;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;
use Tests\CommunityTestCase;

/**
 * Slice 3 green criteria (Plan 06 Phase 4b design §11 slice 3): roles +
 * `WorkspaceAccess` + gates. Covers §10.3 (role matrix at the
 * WorkspaceAccess/gate/UserPolicy level — routes themselves are a later
 * slice), §10.9 (credential visibility, now live through Community's real
 * bindings), and F7's `usersLimit()` count-basis helper (§4.6/§10.13,
 * reusable-hook-only this slice — the Users controller is slice 6).
 */
class Slice3RolesAndGatesTest extends CommunityTestCase
{
    private function installFresh(): void
    {
        Artisan::call('sendtrap:install', [
            '--force' => true,
            '--name' => 'Owner Person',
            '--email' => 'owner@example.org',
            '--password' => 'password1234',
            '--workspace' => 'Sendtrap',
        ]);
    }

    // -- §4.1 users.role column + User model -----------------------------

    public function test_role_column_defaults_to_viewer_at_the_db_level(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Raw Insert',
            'email' => 'raw@example.org',
            'password' => bcrypt('password1234'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('viewer', DB::table('users')->where('id', $id)->value('role'));
    }

    public function test_role_casts_to_the_role_enum(): void
    {
        $user = User::factory()->owner()->create();

        $this->assertInstanceOf(Role::class, $user->fresh()->role);
        $this->assertSame(Role::Owner, $user->fresh()->role);
    }

    public function test_role_helper_methods(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->member()->create();
        $viewer = User::factory()->viewer()->create();

        $this->assertTrue($owner->isOwner());
        $this->assertFalse($owner->isMember());
        $this->assertFalse($owner->isViewer());
        $this->assertTrue($owner->canManageWorkspace());

        $this->assertTrue($member->isMember());
        $this->assertTrue($member->canManageWorkspace());

        $this->assertTrue($viewer->isViewer());
        $this->assertFalse($viewer->canManageWorkspace());
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $user = new User([
            'name' => 'Escalation Attempt',
            'email' => 'escalate@example.org',
            'password' => 'password1234',
            'role' => 'owner',
        ]);

        $this->assertNull($user->role);
    }

    // -- Installer backfill (fixing the slice-2 deviation) ----------------

    public function test_installer_creates_the_first_user_as_owner(): void
    {
        $this->installFresh();

        $owner = User::query()->first();

        $this->assertSame(Role::Owner, $owner->role);
        $this->assertTrue($owner->isOwner());
    }

    // -- §4.2 CommunityWorkspaceAccess ------------------------------------

    public function test_workspace_access_is_bound_to_the_community_implementation(): void
    {
        $this->assertInstanceOf(CommunityWorkspaceAccess::class, app(WorkspaceAccess::class));
    }

    public function test_can_view_is_true_for_every_role(): void
    {
        $this->installFresh();
        $workspace = app(WorkspaceContext::class)->current();
        $access = app(WorkspaceAccess::class);

        foreach ([Role::Owner, Role::Member, Role::Viewer] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertTrue($access->canView($user, $workspace), "canView should be true for {$role->value}");
        }
    }

    public function test_can_manage_is_true_only_for_owner_and_member(): void
    {
        $this->installFresh();
        $workspace = app(WorkspaceContext::class)->current();
        $access = app(WorkspaceAccess::class);

        $this->assertTrue($access->canManage(User::factory()->owner()->create(), $workspace));
        $this->assertTrue($access->canManage(User::factory()->member()->create(), $workspace));
        $this->assertFalse($access->canManage(User::factory()->viewer()->create(), $workspace));
    }

    public function test_workspace_access_returns_false_never_throws_for_a_non_user_object(): void
    {
        $this->installFresh();
        $workspace = app(WorkspaceContext::class)->current();
        $access = app(WorkspaceAccess::class);

        $notAUser = new class
        {
            public $role = 'owner';
        };

        $this->assertFalse($access->canView($notAUser, $workspace));
        $this->assertFalse($access->canManage($notAUser, $workspace));
    }

    // -- §4.8 gates: manage-workspace / manage-instance -------------------

    public function test_manage_workspace_gate_matrix(): void
    {
        $this->installFresh();

        $this->assertTrue(Gate::forUser(User::factory()->owner()->create())->allows('manage-workspace'));
        $this->assertTrue(Gate::forUser(User::factory()->member()->create())->allows('manage-workspace'));
        $this->assertFalse(Gate::forUser(User::factory()->viewer()->create())->allows('manage-workspace'));
    }

    public function test_manage_instance_gate_is_owner_only(): void
    {
        $this->installFresh();

        $this->assertTrue(Gate::forUser(User::factory()->owner()->create())->allows('manage-instance'));
        $this->assertFalse(Gate::forUser(User::factory()->member()->create())->allows('manage-instance'));
        $this->assertFalse(Gate::forUser(User::factory()->viewer()->create())->allows('manage-instance'));
    }

    public function test_manage_workspace_gate_denies_before_install_rather_than_throwing(): void
    {
        // No installFresh() — no workspace exists yet.
        $owner = User::factory()->owner()->create();

        $this->assertFalse(Gate::forUser($owner)->allows('manage-workspace'));
    }

    // -- §4.3 UserPolicy: owner-only CRUD ---------------------------------

    public function test_user_policy_abilities_are_owner_only(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->member()->create();
        $viewer = User::factory()->viewer()->create();
        $target = User::factory()->viewer()->create();

        $this->assertTrue(Gate::forUser($owner)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($member)->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('viewAny', User::class));

        $this->assertTrue(Gate::forUser($owner)->allows('create', User::class));
        $this->assertFalse(Gate::forUser($member)->allows('create', User::class));

        $this->assertTrue(Gate::forUser($owner)->allows('update', $target));
        $this->assertFalse(Gate::forUser($member)->allows('update', $target));

        $this->assertTrue(Gate::forUser($owner)->allows('delete', $target));
        $this->assertFalse(Gate::forUser($member)->allows('delete', $target));
        $this->assertFalse(Gate::forUser($viewer)->allows('delete', $target));
    }

    // -- Last-owner protection ---------------------------------------------

    public function test_owner_cannot_delete_the_last_remaining_owner(): void
    {
        $this->installFresh();
        $onlyOwner = User::query()->where('role', Role::Owner->value)->firstOrFail();

        $this->assertFalse(Gate::forUser($onlyOwner)->allows('delete', $onlyOwner));
        $this->assertTrue((new UserPolicy)->isLastOwner($onlyOwner));
    }

    public function test_owner_can_delete_a_non_owner_target(): void
    {
        $this->installFresh();
        $owner = User::query()->where('role', Role::Owner->value)->firstOrFail();
        $member = User::factory()->member()->create();

        $this->assertTrue(Gate::forUser($owner)->allows('delete', $member));
    }

    public function test_owner_can_delete_one_of_several_owners(): void
    {
        $this->installFresh();
        $firstOwner = User::query()->where('role', Role::Owner->value)->firstOrFail();
        $secondOwner = User::factory()->owner()->create();

        $this->assertTrue(Gate::forUser($firstOwner)->allows('delete', $secondOwner));
        $this->assertTrue(Gate::forUser($firstOwner)->allows('delete', $firstOwner));
        $this->assertFalse((new UserPolicy)->isLastOwner($secondOwner));
    }

    // -- F7: usersLimit() count-basis reusable hook -----------------------

    public function test_within_users_limit_helper_uses_the_live_user_count(): void
    {
        User::factory()->count(2)->viewer()->create();
        $this->assertSame(2, User::query()->count());

        $entitlements = new class implements Entitlements
        {
            public function for(WorkspaceContract $workspace): WorkspaceEntitlements
            {
                return new class implements WorkspaceEntitlements
                {
                    public function sendsPerMinute(): ?int
                    {
                        return null;
                    }

                    public function sendsPerMonth(): ?int
                    {
                        return null;
                    }

                    public function forwardsPerMonth(): ?int
                    {
                        return null;
                    }

                    public function emailSizeBytes(): ?int
                    {
                        return null;
                    }

                    public function projectsLimit(): ?int
                    {
                        return null;
                    }

                    public function inboxesLimit(): ?int
                    {
                        return null;
                    }

                    public function usersLimit(): ?int
                    {
                        return 2;
                    }

                    public function messagesPerInbox(): ?int
                    {
                        return null;
                    }

                    public function retentionDays(): ?int
                    {
                        return null;
                    }

                    public function storageBytesLimit(): ?int
                    {
                        return null;
                    }

                    public function apiRequestsPerMinute(): ?int
                    {
                        return null;
                    }

                    public function hasApiAccess(): bool
                    {
                        return true;
                    }

                    public function hasSupport(): bool
                    {
                        return false;
                    }

                    public function hasHtmlCheckApi(): bool
                    {
                        return true;
                    }

                    public function within(string $name, int $current): bool
                    {
                        return $name === 'users' ? $current < 2 : true;
                    }
                };
            }
        };

        $workspace = new class implements WorkspaceContract
        {
            public function id(): int
            {
                return 1;
            }

            public function name(): string
            {
                return 'Fake';
            }
        };

        // Already at the configured limit of 2 users — the (3rd) create is
        // rejected.
        $this->assertFalse((new UserPolicy)->withinUsersLimit($entitlements, $workspace));

        User::query()->latest('id')->first()->delete();
        $this->assertSame(1, User::query()->count());
        $this->assertTrue((new UserPolicy)->withinUsersLimit($entitlements, $workspace));
    }

    public function test_installer_first_owner_is_never_blocked_by_the_users_limit(): void
    {
        // The installer creates the first owner directly (never through
        // withinUsersLimit()/a Users-page create), so it succeeds even
        // though at the moment of creation the "limit" would already be at
        // its ceiling for a hypothetical limit of 1.
        $this->installFresh();

        $this->assertSame(1, User::query()->count());
        $this->assertTrue(User::query()->first()->isOwner());
    }

    /**
     * Slice 4 (Plan 06 Phase 4b design §11 slice 4): now that
     * `Entitlements`/`CommunityEntitlements` are bound
     * (`CommunityServiceProvider::register()`), `withinUsersLimit()` is
     * exercised through the real container-resolved `Entitlements` and the
     * real singleton `Workspace` — not the anonymous fakes above — proving
     * the hook is actually wired to `config('sendtrap-community.limits.
     * users')`, not just structurally compatible with the contract.
     */
    public function test_within_users_limit_helper_is_wired_to_the_real_bound_entitlements(): void
    {
        config(['sendtrap-community.limits.users' => 2]);
        $this->installFresh();
        $workspace = app(WorkspaceContext::class)->current();
        $entitlements = app(Entitlements::class);

        // The installer's owner is user #1; one more fits under the limit
        // of 2.
        $this->assertSame(1, User::query()->count());
        $this->assertTrue((new UserPolicy)->withinUsersLimit($entitlements, $workspace));

        User::factory()->viewer()->create();
        $this->assertSame(2, User::query()->count());
        $this->assertFalse((new UserPolicy)->withinUsersLimit($entitlements, $workspace));

        // D-17: an absent (unset) users limit is unlimited, even with many
        // users already present.
        config(['sendtrap-community.limits.users' => null]);
        $this->assertTrue((new UserPolicy)->withinUsersLimit($entitlements, $workspace));
    }

    // -- §4.7/§10.9 Credential visibility, now live through the real bindings

    private function seedInbox(): array
    {
        $this->installFresh();
        $workspace = Workspace::query()->first();
        $project = $workspace->projects()->create(['name' => 'Project One']);
        $inbox = $project->inboxes()->create([
            'name' => 'Inbox One',
            'smtp_username' => 'seeded-smtp-username',
            'smtp_password' => 'seeded-smtp-password',
            'api_token' => 'seeded-api-token',
        ]);

        return [$workspace, $inbox];
    }

    private function resourceArrayFor(Inbox $inbox, ?User $user): array
    {
        $request = Request::create('/inboxes/'.$inbox->id);
        $request->setUserResolver(fn () => $user);

        return (new InboxResource($inbox))->resolve($request);
    }

    public function test_viewer_does_not_receive_inbox_credentials(): void
    {
        [, $inbox] = $this->seedInbox();
        $viewer = User::factory()->viewer()->create();

        $payload = $this->resourceArrayFor($inbox, $viewer);

        $this->assertArrayNotHasKey('smtp_username', $payload);
        $this->assertArrayNotHasKey('smtp_password', $payload);
        $this->assertArrayNotHasKey('api_token', $payload);

        $json = json_encode($payload);
        $this->assertStringNotContainsString('seeded-smtp-username', $json);
        $this->assertStringNotContainsString('seeded-smtp-password', $json);
        $this->assertStringNotContainsString('seeded-api-token', $json);
    }

    public function test_member_and_owner_receive_inbox_credentials(): void
    {
        [, $inbox] = $this->seedInbox();

        foreach ([User::factory()->member()->create(), User::factory()->owner()->create()] as $user) {
            $payload = $this->resourceArrayFor($inbox, $user);

            $this->assertSame('seeded-smtp-username', $payload['smtp_username']);
            $this->assertSame('seeded-smtp-password', $payload['smtp_password']);
            $this->assertSame('seeded-api-token', $payload['api_token']);
        }
    }
}
