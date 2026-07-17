<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\CommunityWorkspaceContext;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Models\Workspace;
use Tests\CommunityTestCase;

/**
 * Slice 2 green criteria (Plan 06 Phase 4b design §11 slice 2): installer
 * idempotency + exactly-one-workspace + no-lifecycle-routes +
 * second-workspace-throws (§10.1, §10.2, §10.10), plus the
 * `CommunityWorkspaceContext` singleton-resolution/self-heal/multi-row
 * behaviour §3.2/§3.3 describe.
 */
class Slice2InstallerAndWorkspaceTest extends CommunityTestCase
{
    /**
     * Runs the installer non-interactively with fixed creds, mirroring the
     * design's `installFresh()` test helper (§10 "Fixtures / harness").
     */
    private function installFresh(): int
    {
        return Artisan::call('sendtrap:install', [
            '--force' => true,
            '--name' => 'Owner Person',
            '--email' => 'owner@example.org',
            '--password' => 'password1234',
            '--workspace' => 'Sendtrap',
        ]);
    }

    // -- §10.1 Installer idempotency ----------------------------------

    public function test_fresh_install_creates_exactly_one_workspace_and_owner(): void
    {
        $exitCode = $this->installFresh();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, User::query()->count());

        $workspace = Workspace::query()->first();
        $this->assertSame('Sendtrap', $workspace->name);

        $owner = User::query()->first();
        $this->assertSame('Owner Person', $owner->name);
        $this->assertSame('owner@example.org', $owner->email);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue(Hash::check('password1234', $owner->password));

        $this->assertSame($workspace->id, InstanceSettings::get('workspace_id'));
    }

    public function test_fresh_install_creates_a_starter_project_with_a_credentialed_inbox(): void
    {
        $this->installFresh();

        $workspace = Workspace::query()->first();
        $this->assertSame(1, $workspace->projects()->count());

        $project = $workspace->projects()->first();
        $this->assertSame('My app', $project->name);
        $this->assertSame(1, $project->inboxes()->count());

        $inbox = $project->inboxes()->first();
        $this->assertSame('Testing', $inbox->name);
        $this->assertNotEmpty($inbox->smtp_username);
        $this->assertNotEmpty($inbox->smtp_password);
        $this->assertNotEmpty($inbox->api_token);
    }

    public function test_re_running_the_installer_never_duplicates_the_starter_project(): void
    {
        $this->installFresh();
        Workspace::query()->first()->projects()->first()->update(['name' => 'Renamed by user']);

        $this->installFresh();

        $this->assertSame(1, Workspace::query()->first()->projects()->count());
        $this->assertSame('Renamed by user', Workspace::query()->first()->projects()->value('name'));
    }

    public function test_re_running_the_installer_is_idempotent(): void
    {
        $this->installFresh();
        $firstWorkspaceId = Workspace::query()->value('id');
        $firstUserId = User::query()->value('id');

        $exitCode = $this->installFresh();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, Workspace::query()->count(), 'A second run must not create a second workspace.');
        $this->assertSame(1, User::query()->count(), 'A second run must not create a second owner.');
        $this->assertSame($firstWorkspaceId, Workspace::query()->value('id'));
        $this->assertSame($firstUserId, User::query()->value('id'));
        $this->assertSame($firstWorkspaceId, InstanceSettings::get('workspace_id'), 'The pointer must stay stable across re-runs.');
    }

    public function test_installer_accepts_a_custom_workspace_name(): void
    {
        Artisan::call('sendtrap:install', [
            '--force' => true,
            '--name' => 'Owner Person',
            '--email' => 'owner@example.org',
            '--password' => 'password1234',
            '--workspace' => 'My Company Sandbox',
        ]);

        $this->assertSame('My Company Sandbox', Workspace::query()->value('name'));
    }

    public function test_installer_defaults_workspace_name_when_not_given(): void
    {
        Artisan::call('sendtrap:install', [
            '--force' => true,
            '--name' => 'Owner Person',
            '--email' => 'owner@example.org',
            '--password' => 'password1234',
        ]);

        $this->assertSame('Sendtrap', Workspace::query()->value('name'));
    }

    public function test_non_interactive_force_run_fails_cleanly_when_a_required_owner_field_is_missing(): void
    {
        // --force with a fresh install and no users/email/password supplied
        // must fail deterministically rather than hang waiting on a prompt
        // with no TTY.
        $exitCode = Artisan::call('sendtrap:install', ['--force' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, User::query()->count());
        // The workspace step still runs (it needs no owner input) — only
        // the owner step is guarded.
        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_installer_migrates_from_empty(): void
    {
        // CommunityTestCase's RefreshDatabase already migrated for us, but
        // the installer's own migrate --force call must be a safe no-op
        // (already-run migrations are skipped by basename), not a failure.
        $exitCode = $this->installFresh();

        $this->assertSame(0, $exitCode);
    }

    // -- §10.2 Exactly-one-workspace + no lifecycle routes -------------

    public function test_exactly_one_workspace_exists_after_install(): void
    {
        $this->installFresh();

        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_no_workspace_lifecycle_route_exists(): void
    {
        $this->installFresh();

        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString(
                'workspaces.',
                (string) $route->getName(),
                "Found an unexpected workspace-lifecycle route name: {$route->getName()}",
            );
        }

        foreach (['/workspaces', '/workspace/switch', '/workspaces/1'] as $path) {
            $this->post($path)->assertNotFound();
            $this->put($path)->assertNotFound();
            $this->delete($path)->assertNotFound();
        }
    }

    // -- §3.2 / §10 WorkspaceContext singleton resolution --------------

    public function test_workspace_context_current_resolves_the_installed_singleton(): void
    {
        $this->installFresh();
        $workspace = Workspace::query()->first();

        $resolved = app(WorkspaceContext::class)->current();

        $this->assertInstanceOf(Workspace::class, $resolved);
        $this->assertSame($workspace->id, $resolved->id());
        $this->assertSame($workspace->name, $resolved->name());
    }

    public function test_workspace_context_is_bound_as_a_container_singleton(): void
    {
        $this->assertSame(
            app(WorkspaceContext::class),
            app(WorkspaceContext::class),
            'WorkspaceContext must be bound as a singleton (§3.2/§3.3 — one DB load per request at most).',
        );
    }

    public function test_workspace_context_current_returns_null_before_install(): void
    {
        $this->assertNull(app(WorkspaceContext::class)->current());
    }

    public function test_workspace_context_for_inbox_id_resolves_through_the_inbox_chain(): void
    {
        $this->installFresh();
        $workspace = Workspace::query()->first();
        $project = $workspace->projects()->create(['name' => 'Project One']);
        $inbox = $project->inboxes()->create([
            'name' => 'Inbox One',
            'smtp_username' => 'user1',
            'smtp_password' => 'secret1',
            'api_token' => 'token1',
        ]);

        $resolved = app(WorkspaceContext::class)->forInboxId($inbox->id);

        $this->assertInstanceOf(Workspace::class, $resolved);
        $this->assertSame($workspace->id, $resolved->id());
    }

    public function test_workspace_context_for_inbox_id_returns_null_for_an_unknown_inbox(): void
    {
        $this->installFresh();

        $this->assertNull(app(WorkspaceContext::class)->forInboxId(999999));
    }

    public function test_workspace_context_all_yields_the_one_workspace(): void
    {
        $this->installFresh();
        $workspace = Workspace::query()->first();

        $all = iterator_to_array(app(WorkspaceContext::class)->all());

        $this->assertCount(1, $all);
        $this->assertSame($workspace->id, $all[0]->id());
    }

    // -- §3.3 pointer self-heal -----------------------------------------

    public function test_pointer_self_heals_when_unset_and_exactly_one_workspace_row_exists(): void
    {
        $this->installFresh();
        $workspace = Workspace::query()->first();

        InstanceSettings::forget('workspace_id');
        $this->assertNull(InstanceSettings::get('workspace_id'));

        // A fresh context instance (the bound singleton is not re-created
        // mid-test) — resolve directly to exercise singleton() from a clean
        // memoisation state.
        $context = new CommunityWorkspaceContext;
        $resolved = $context->current();

        $this->assertInstanceOf(Workspace::class, $resolved);
        $this->assertSame($workspace->id, $resolved->id());
        $this->assertSame(
            $workspace->id,
            InstanceSettings::get('workspace_id'),
            'The pointer must be re-persisted from the sole surviving row.',
        );
    }

    public function test_pointer_self_heals_when_stale(): void
    {
        $this->installFresh();
        $workspace = Workspace::query()->first();

        InstanceSettings::put('workspace_id', $workspace->id + 999);

        $context = new CommunityWorkspaceContext;
        $resolved = $context->current();

        $this->assertSame($workspace->id, $resolved->id());
        $this->assertSame($workspace->id, InstanceSettings::get('workspace_id'));
    }

    // -- Multi-row via raw SQL: deterministic failure --------------------

    public function test_multiple_workspace_rows_via_raw_sql_fail_deterministically(): void
    {
        $this->installFresh();

        InstanceSettings::forget('workspace_id');

        // Bypass Eloquent (and therefore the Workspace::creating guard)
        // entirely, exactly the residual trust boundary §3.5 documents.
        DB::table('workspaces')->insert([
            'name' => 'Rogue Second Workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, Workspace::query()->count());

        $context = new CommunityWorkspaceContext;

        $this->expectException(RuntimeException::class);
        $context->current();
    }

    public function test_multiple_workspace_rows_fail_on_every_call_not_just_the_first(): void
    {
        $this->installFresh();
        InstanceSettings::forget('workspace_id');
        DB::table('workspaces')->insert([
            'name' => 'Rogue Second Workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $context = new CommunityWorkspaceContext;

        $failures = 0;
        foreach (range(1, 3) as $_) {
            try {
                $context->current();
            } catch (RuntimeException) {
                $failures++;
            }
        }

        $this->assertSame(3, $failures, 'The ambiguous multi-row state must not be memoised into a stale null.');
    }

    // -- §3.5 / §10.10 Second-workspace prevention -----------------------

    public function test_creating_a_second_workspace_throws(): void
    {
        $this->installFresh();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Community is single-workspace');

        Workspace::factory()->create();
    }

    public function test_creating_a_second_workspace_does_not_persist_a_row(): void
    {
        $this->installFresh();

        try {
            Workspace::factory()->create();
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_installer_re_run_does_not_trip_the_singleton_guard(): void
    {
        $this->installFresh();

        // The installer's own "found" branch (firstOrCreate-when-none) must
        // not trip the creating guard on a re-run.
        $exitCode = $this->installFresh();

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, Workspace::query()->count());
    }

    // -- Setting / InstanceSettings plumbing -----------------------------

    public function test_instance_settings_put_and_get_round_trip(): void
    {
        InstanceSettings::put('some_key', 'some value');

        $this->assertSame('some value', InstanceSettings::get('some_key'));
        $this->assertSame(1, Setting::query()->where('key', 'some_key')->count());
    }

    public function test_instance_settings_get_returns_default_when_missing(): void
    {
        $this->assertSame('fallback', InstanceSettings::get('does_not_exist', 'fallback'));
        $this->assertNull(InstanceSettings::get('does_not_exist'));
    }

    public function test_instance_settings_put_overwrites_an_existing_key(): void
    {
        InstanceSettings::put('workspace_id', 1);
        InstanceSettings::put('workspace_id', 2);

        $this->assertSame(2, InstanceSettings::get('workspace_id'));
        $this->assertSame(1, Setting::query()->where('key', 'workspace_id')->count());
    }
}
