<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Testing\Concerns\InteractsWithSmtpServer;
use Tests\CommunityTestCase;

/**
 * Slice 6 green criteria (Plan 06 Phase 4b design §11 slice 6): the
 * owner-only slice of the role matrix (users.* / settings*, §4.8) and
 * users-limit enforcement (§10.13, F7). Plus the slice-3
 * orchestrator-ratified last-owner guard over HTTP (delete AND the
 * design-silent demote/self-delete arms — flagged in the slice-6 report),
 * and the §8-row-10 instance allowlist: the Settings page writes
 * `workspace.allowed_ips` and the package's own SMTP AUTH enforcement
 * (`Inbox::effectiveAllowedIps()` workspace tier) rejects a session from a
 * disallowed IP — proven on the wire via InteractsWithSmtpServer.
 */
class Slice6UsersAndSettingsTest extends CommunityTestCase
{
    use InteractsWithSmtpServer;

    protected function setUp(): void
    {
        parent::setUp();

        // Inertia pages render through resources/views/app.blade.php,
        // whose @vite directive would demand a built manifest in tests.
        $this->withoutVite();
    }

    private function installFresh(): Workspace
    {
        Artisan::call('sendtrap:install', [
            '--force' => true,
            '--name' => 'Owner Person',
            '--email' => 'owner@example.org',
            '--password' => 'password1234',
            '--workspace' => 'Sendtrap',
        ]);

        return Workspace::query()->firstOrFail();
    }

    private function installerOwner(): User
    {
        return User::query()->where('email', 'owner@example.org')->firstOrFail();
    }

    // -- §10.3 owner-only slice of the role matrix: users.* / settings* ----

    /**
     * @return array<string, array{string, string, string, array<string, mixed>}>
     */
    public static function nonOwnerDeniedRoutes(): array
    {
        $rows = [];
        foreach (['member', 'viewer'] as $role) {
            $rows["{$role} GET users.index"] = [$role, 'get', 'users.index', []];
            $rows["{$role} POST users.store"] = [$role, 'post', 'users.store', ['name' => 'X', 'email' => 'x@example.org', 'password' => 'password1234', 'role' => 'viewer']];
            $rows["{$role} PUT users.update"] = [$role, 'put', 'users.update', ['role' => 'member']];
            $rows["{$role} DELETE users.destroy"] = [$role, 'delete', 'users.destroy', []];
            $rows["{$role} GET settings"] = [$role, 'get', 'settings', []];
            $rows["{$role} PUT settings.update"] = [$role, 'put', 'settings.update', ['name' => 'Nope']];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('nonOwnerDeniedRoutes')]
    public function test_member_and_viewer_get_403_on_every_users_and_settings_route(
        string $role,
        string $method,
        string $routeName,
        array $payload,
    ): void {
        $this->installFresh();
        $target = User::factory()->viewer()->create();
        $actor = User::factory()->{$role}()->create();

        $url = in_array($routeName, ['users.update', 'users.destroy'], true)
            ? route($routeName, $target)
            : route($routeName);

        $response = $method === 'get'
            ? $this->actingAs($actor)->get($url)
            : $this->actingAs($actor)->{$method}($url, $payload);

        $response->assertForbidden();
    }

    public function test_unauthenticated_users_and_settings_requests_redirect_to_login(): void
    {
        $this->installFresh();

        $this->get(route('users.index'))->assertRedirect(route('login'));
        $this->get(route('settings'))->assertRedirect(route('login'));
    }

    // -- §4.6 owner CRUD happy paths ----------------------------------------

    public function test_owner_sees_the_users_page_with_the_user_list_and_roles(): void
    {
        $this->installFresh();
        $owner = $this->installerOwner();
        User::factory()->viewer()->create(['name' => 'View Only']);

        $response = $this->actingAs($owner)->get(route('users.index'))->assertOk();

        $body = $response->getContent();
        $this->assertStringContainsString('Users\/Index', $body);
        $this->assertStringContainsString('Owner Person', $body);
        $this->assertStringContainsString('View Only', $body);
        $this->assertStringContainsString('viewer', $body);
    }

    public function test_owner_creates_a_user_with_a_role_verified_email_and_working_password(): void
    {
        $this->installFresh();
        $owner = $this->installerOwner();

        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'New Member',
            'email' => 'member@example.org',
            'password' => 'password1234',
            'role' => 'member',
        ])->assertRedirect();

        $created = User::query()->where('email', 'member@example.org')->firstOrFail();
        $this->assertSame(Role::Member, $created->role);
        // §4.6: instance-admin provisioning, no verification-mail flow —
        // created users are verified at creation, like the installer owner.
        $this->assertNotNull($created->email_verified_at);
        $this->assertTrue(Hash::check('password1234', $created->password));
    }

    public function test_create_validation_rejects_duplicate_email_and_unknown_role(): void
    {
        $this->installFresh();
        $owner = $this->installerOwner();

        $this->actingAs($owner)->from(route('users.index'))->post(route('users.store'), [
            'name' => 'Dupe',
            'email' => 'owner@example.org',
            'password' => 'password1234',
            'role' => 'member',
        ])->assertRedirect(route('users.index'))->assertSessionHasErrors('email');

        $this->actingAs($owner)->from(route('users.index'))->post(route('users.store'), [
            'name' => 'Bad Role',
            'email' => 'badrole@example.org',
            'password' => 'password1234',
            'role' => 'superadmin',
        ])->assertSessionHasErrors('role');

        $this->assertSame(1, User::query()->count());
    }

    public function test_role_is_never_mass_assignable_through_extra_request_fields(): void
    {
        $this->installFresh();
        $owner = $this->installerOwner();

        // A stray duplicate/extra field must not bypass the explicit
        // assignment (User::$fillable excludes role — §4.1).
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.org',
            'password' => 'password1234',
            'role' => 'viewer',
            'is_admin' => true,
        ])->assertRedirect();

        $this->assertSame(Role::Viewer, User::query()->where('email', 'sneaky@example.org')->firstOrFail()->role);
    }

    // -- §10.13 users limit, both directions (F7) ---------------------------

    public function test_at_limit_create_is_blocked_with_the_configured_limit(): void
    {
        config(['sendtrap-community.limits.users' => 2]);
        $this->installFresh(); // installer's first owner = user 1
        $owner = $this->installerOwner();

        // 2nd user: User::count() = 1 < 2 → allowed.
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Second User',
            'email' => 'second@example.org',
            'password' => 'password1234',
            'role' => 'member',
        ])->assertRedirect();

        // 3rd user: User::count() = 2, not < 2 → rejected BEFORE create.
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'Third User',
            'email' => 'third@example.org',
            'password' => 'password1234',
            'role' => 'member',
        ])->assertForbidden();

        $this->assertSame(2, User::query()->count());
        $this->assertNull(User::query()->where('email', 'third@example.org')->first());
    }

    public function test_absent_users_limit_means_unlimited(): void
    {
        config(['sendtrap-community.limits.users' => null]);
        $this->installFresh();
        $owner = $this->installerOwner();

        foreach (range(1, 5) as $i) {
            $this->actingAs($owner)->post(route('users.store'), [
                'name' => "User {$i}",
                'email' => "user{$i}@example.org",
                'password' => 'password1234',
                'role' => 'viewer',
            ])->assertRedirect();
        }

        $this->assertSame(6, User::query()->count());
    }

    public function test_installer_first_owner_is_exempt_from_a_low_users_limit(): void
    {
        // §4.6/§10.13: even a limit the live count already saturates never
        // blocks bootstrapping the one required owner — `sendtrap:install`
        // creates it directly, outside the Users-page check.
        config(['sendtrap-community.limits.users' => 1]);
        $this->installFresh();

        $this->assertSame(1, User::query()->count());
        $owner = $this->installerOwner();
        $this->assertSame(Role::Owner, $owner->role);

        // ...but the Users page IS blocked at that same limit.
        $this->actingAs($owner)->post(route('users.store'), [
            'name' => 'One Too Many',
            'email' => 'toomany@example.org',
            'password' => 'password1234',
            'role' => 'viewer',
        ])->assertForbidden();
    }

    // -- §4.6 role change + the last-owner demote guard ----------------------

    public function test_owner_changes_another_users_role(): void
    {
        $this->installFresh();
        $owner = $this->installerOwner();
        $member = User::factory()->member()->create();

        $this->actingAs($owner)->put(route('users.update', $member), ['role' => 'owner'])
            ->assertRedirect();

        $this->assertSame(Role::Owner, $member->fresh()->role);
    }

    public function test_owner_may_demote_themselves_when_another_owner_exists(): void
    {
        $this->installFresh();
        $owner = $this->installerOwner();
        User::factory()->owner()->create();

        $this->actingAs($owner)->put(route('users.update', $owner), ['role' => 'member'])
            ->assertRedirect();

        $this->assertSame(Role::Member, $owner->fresh()->role);
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        $this->installFresh();
        $owner = $this->installerOwner();
        User::factory()->member()->create();

        $this->actingAs($owner)->put(route('users.update', $owner), ['role' => 'member'])
            ->assertForbidden();

        $this->assertSame(Role::Owner, $owner->fresh()->role);
    }

    // -- last-owner delete guard (+ the flagged self-delete rule) over HTTP --

    public function test_owner_deletes_another_user(): void
    {
        $this->installFresh();
        $owner = $this->installerOwner();
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($owner)->delete(route('users.destroy', $viewer))->assertRedirect();

        $this->assertNull($viewer->fresh());
    }

    public function test_owner_deletes_another_owner_when_two_exist(): void
    {
        $this->installFresh();
        $owner = $this->installerOwner();
        $secondOwner = User::factory()->owner()->create();

        $this->actingAs($owner)->delete(route('users.destroy', $secondOwner))->assertRedirect();

        $this->assertNull($secondOwner->fresh());
    }

    public function test_the_sole_owner_cannot_delete_themselves(): void
    {
        // The last-owner guard over HTTP: the sole owner deleting
        // themselves is the only reachable last-owner-delete case (any
        // OTHER actor able to hit users.destroy is a second owner, making
        // the target not-last).
        $this->installFresh();
        $owner = $this->installerOwner();

        $this->actingAs($owner)->delete(route('users.destroy', $owner))->assertForbidden();

        $this->assertNotNull($owner->fresh());
    }

    public function test_self_delete_is_allowed_with_a_second_owner_and_logs_the_user_out(): void
    {
        // Design-silent self-delete rule (flagged): the last-owner guard
        // pattern applied verbatim and nothing stricter — the semantics the
        // slice-3 policy tests pinned. The controller invalidates the
        // session so the request doesn't continue on a deleted account.
        $this->installFresh();
        $owner = $this->installerOwner();
        User::factory()->owner()->create();

        $this->actingAs($owner)
            ->delete(route('users.destroy', $owner))
            ->assertRedirect(route('login'));

        $this->assertNull($owner->fresh());
        $this->assertGuest();
    }

    // -- §4.6 Settings page: name + instance allowlist + read-only limits ----

    public function test_owner_sees_the_settings_page_with_workspace_and_limits(): void
    {
        config(['sendtrap-community.limits.sends_per_month' => 5000]);
        $workspace = $this->installFresh();
        $workspace->update(['allowed_ips' => ['203.0.113.7']]);
        $owner = $this->installerOwner();

        $body = $this->actingAs($owner)->get(route('settings'))->assertOk()->getContent();

        $this->assertStringContainsString('Settings\/Index', $body);
        $this->assertStringContainsString('Sendtrap', $body);
        $this->assertStringContainsString('203.0.113.7', $body);
        // The read-only limits display gets the whole config block.
        $this->assertStringContainsString('sends_per_month', $body);
        $this->assertStringContainsString('5000', $body);
    }

    public function test_owner_updates_the_workspace_name_and_instance_allowlist_normalized(): void
    {
        $workspace = $this->installFresh();
        $owner = $this->installerOwner();

        $this->actingAs($owner)->put(route('settings.update'), [
            'name' => 'Renamed Instance',
            // Whitespace + duplicates: IpAllowList::normalize() must trim
            // and de-dupe (the Cloud TeamAccessController pattern §4.6 cites).
            'allowed_ips' => [' 203.0.113.7 ', '198.51.100.0/24', '203.0.113.7'],
        ])->assertRedirect();

        $workspace->refresh();
        $this->assertSame('Renamed Instance', $workspace->name);
        $this->assertSame(['203.0.113.7', '198.51.100.0/24'], $workspace->allowed_ips);
    }

    public function test_settings_update_rejects_an_invalid_allowlist_rule(): void
    {
        $workspace = $this->installFresh();
        $owner = $this->installerOwner();

        $this->actingAs($owner)->from(route('settings'))->put(route('settings.update'), [
            'name' => 'Sendtrap',
            'allowed_ips' => ['not-an-ip'],
        ])->assertRedirect(route('settings'))->assertSessionHasErrors('allowed_ips.0');

        $this->assertNull($workspace->fresh()->allowed_ips);
    }

    public function test_clearing_the_allowlist_stores_null(): void
    {
        $workspace = $this->installFresh();
        $workspace->update(['allowed_ips' => ['203.0.113.7']]);
        $owner = $this->installerOwner();

        $this->actingAs($owner)->put(route('settings.update'), [
            'name' => 'Sendtrap',
            'allowed_ips' => [],
        ])->assertRedirect();

        $this->assertNull($workspace->fresh()->allowed_ips);
    }

    // -- §8 row 10: the written allowlist is what core enforcement reads -----

    public function test_settings_write_flows_into_inbox_effective_allowed_ips(): void
    {
        $workspace = $this->installFresh();
        $owner = $this->installerOwner();
        $project = $workspace->projects()->create(['name' => 'Project One']);
        $inbox = $project->inboxes()->create(['name' => 'Inbox One']);

        $this->actingAs($owner)->put(route('settings.update'), [
            'name' => 'Sendtrap',
            'allowed_ips' => ['203.0.113.0/24'],
        ])->assertRedirect();

        // Fresh model: the workspace tier of effectiveAllowedIps() (the
        // inbox and project levels are unset) resolves to the written list.
        $this->assertSame(
            ['203.0.113.0/24'],
            Inbox::query()->findOrFail($inbox->id)->effectiveAllowedIps(),
        );
    }

    // -- §8 row 10 wire proof: SMTP AUTH enforces workspace.allowed_ips ------

    /**
     * @return array<int, array{send?: string, expect?: string}>
     */
    private function authSteps(Inbox $inbox): array
    {
        return [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->smtpAuthLoginSteps($inbox->smtp_username, $inbox->smtp_password),
        ];
    }

    public function test_smtp_auth_is_denied_from_a_disallowed_ip_after_a_settings_allowlist_write(): void
    {
        $workspace = $this->installFresh();
        $owner = $this->installerOwner();
        $project = $workspace->projects()->create(['name' => 'Project One']);
        $inbox = $project->inboxes()->create([
            'name' => 'Inbox One',
            'smtp_username' => 'seeded-smtp-username',
            'smtp_password' => 'seeded-smtp-password',
        ]);

        // The wire harness connects from 127.0.0.1; an allowlist that does
        // NOT contain it makes the test client the "disallowed IP".
        $this->actingAs($owner)->put(route('settings.update'), [
            'name' => 'Sendtrap',
            'allowed_ips' => ['203.0.113.0/24'],
        ])->assertRedirect();

        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, [
            ...$this->authSteps($inbox->fresh()),
            ['expect' => '/^535 5\.7\.1 Access denied for your IP address\r\n$/'],
        ]);

        // And the inverse: an allowlist containing the client IP admits it.
        $this->actingAs($owner)->put(route('settings.update'), [
            'name' => 'Sendtrap',
            'allowed_ips' => ['127.0.0.1', '203.0.113.0/24'],
        ])->assertRedirect();

        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, [
            ...$this->authSteps($inbox->fresh()),
            ['expect' => '/^235 /'],
        ]);
    }
}
