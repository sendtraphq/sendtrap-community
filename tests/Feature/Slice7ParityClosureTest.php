<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CommunityWorkspaceContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Testing\Concerns\InteractsWithSmtpServer;
use Tests\CommunityTestCase;

/**
 * Slice 7 (Plan 06 Phase 4b design §11 slice 7) — parity closure. The
 * §8 rows the earlier slices left open, each pinned by a test here:
 *
 *  - row 28: /docs/api ships (Community-authored, plan-note-free);
 *  - row 31: terms/policy stub routes ship (public);
 *  - row 5:  mail:prune is SCHEDULED daily and runs green against
 *            Community's WorkspaceContext/Entitlements bindings,
 *            honouring retention_days from instance config;
 *  - row 4:  local AND s3 disks exist in config simultaneously (the
 *            storage:migrate-to-s3 docblock-item-4 requirement) and the
 *            command itself is registered;
 *  - rows 6/8 (§10.5's API leg): after a real SMTP DATA the message is
 *            readable via GET /api/v1/messages and POST /api/v1/assert
 *            under the seeded inbox's own token — the wire proof that the
 *            package's ingestion + API resolve under Community's bindings;
 *  - row 7:  the Mailtrap-compatible alias surface answers under a
 *            Community token.
 *
 * PARITY.md at the repo root maps all 33 matrix rows to their mechanism +
 * evidence; this file is the closure for the rows listed above.
 */
class Slice7ParityClosureTest extends CommunityTestCase
{
    use InteractsWithSmtpServer;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function seedInbox(Workspace $workspace): object
    {
        $project = $workspace->projects()->create(['name' => 'Project One']);

        return $project->inboxes()->create([
            'name' => 'Inbox One',
            'smtp_username' => 'seeded-smtp-username',
            'smtp_password' => 'seeded-smtp-password',
            'api_token' => 'seeded-api-token',
        ]);
    }

    private function seedMessage(object $inbox, string $subject, ?\DateTimeInterface $receivedAt = null): Message
    {
        $raw = "Subject: {$subject}\r\n\r\nBody\r\n";
        $path = 'messages/slice7-'.md5($subject).'.eml';
        Storage::disk('local')->put($path, $raw);

        return $inbox->messages()->create([
            'message_id' => md5($subject).'@example.com',
            'from_address' => 'alice@example.com',
            'from_name' => 'Alice Sender',
            'to' => [['address' => 'bob@example.com', 'name' => 'Bob']],
            'cc' => [],
            'subject' => $subject,
            'size' => strlen($raw),
            'is_read' => false,
            'has_html' => false,
            'has_text' => true,
            'has_attachments' => false,
            'raw_path' => $path,
            'received_at' => $receivedAt ?? now(),
        ]);
    }

    // -- Row 28: API docs page ----------------------------------------------

    public function test_docs_api_redirects_guests_to_login(): void
    {
        $this->installFresh();

        $this->get('/docs/api')->assertRedirect('/login');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roles(): array
    {
        return ['owner' => ['owner'], 'member' => ['member'], 'viewer' => ['viewer']];
    }

    #[DataProvider('roles')]
    public function test_docs_api_renders_for_every_authenticated_role(string $role): void
    {
        $this->installFresh();
        $user = User::factory()->{$role}()->create();

        $this->actingAs($user)
            ->get(route('docs.api'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Docs/Api'));
    }

    public function test_docs_api_page_source_carries_no_cloud_plan_vocabulary(): void
    {
        // Row 28's "minus Cloud-plan notes" clause, pinned at the source
        // level: no plan tiers, no upgrade copy, no Cloud pricing links.
        $source = file_get_contents(resource_path('js/Pages/Docs/Api.vue'));

        foreach (['Starter', 'Business', 'Enterprise', 'upgrade', 'pricing', '/register'] as $token) {
            $this->assertFalse(
                str_contains($source, $token),
                "Docs/Api.vue must not contain Cloud-plan vocabulary \"{$token}\""
            );
        }
    }

    // -- Row 31: terms/policy stubs -------------------------------------------

    public function test_terms_and_policy_stub_routes_are_public(): void
    {
        $this->installFresh();

        $this->get('/terms')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal/Terms'));

        $this->get('/policy')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal/Policy'));
    }

    // -- Row 5: retention — schedule registration + live run ------------------

    public function test_mail_prune_is_scheduled_daily(): void
    {
        // bootstrap/app.php's withSchedule() callback attaches via
        // Artisan::starting(), so boot the console application first —
        // exactly what the real `php artisan schedule:run` invocation does.
        $this->artisan('schedule:list')
            ->expectsOutputToContain('mail:prune')
            ->assertSuccessful();

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'mail:prune'));

        $this->assertCount(1, $events, 'exactly one mail:prune schedule entry expected');
        $this->assertSame('0 0 * * *', $events->first()->expression, 'mail:prune must run daily');
    }

    public function test_mail_prune_runs_green_with_no_retention_configured_and_keeps_everything(): void
    {
        Storage::fake('local');
        config(['sendtrap-community.limits.retention_days' => null]);
        $workspace = $this->installFresh();
        $inbox = $this->seedInbox($workspace);
        $this->seedMessage($inbox, 'Ancient message', now()->subDays(400));

        $this->artisan('mail:prune')->assertSuccessful();

        $this->assertSame(1, Message::count(), 'unlimited retention must prune nothing');
    }

    public function test_mail_prune_honours_retention_days_from_instance_config(): void
    {
        Storage::fake('local');
        config(['sendtrap-community.limits.retention_days' => 30]);
        $workspace = $this->installFresh();
        $inbox = $this->seedInbox($workspace);
        $old = $this->seedMessage($inbox, 'Past the window', now()->subDays(31));
        $fresh = $this->seedMessage($inbox, 'Inside the window', now()->subDays(2));

        $this->artisan('mail:prune')->assertSuccessful();

        $this->assertDatabaseMissing('messages', ['id' => $old->id]);
        $this->assertDatabaseHas('messages', ['id' => $fresh->id]);
    }

    // -- Row 4: storage — both adapters present, migrate command registered ---

    public function test_local_and_s3_disks_are_both_configured_with_local_the_default(): void
    {
        // storage:migrate-to-s3 needs disks literally named `local` AND
        // `s3` simultaneously (host-requirements docblock item 4 — §6.4).
        $this->assertSame('local', config('filesystems.default'));
        $this->assertSame('local', config('filesystems.disks.local.driver'));
        $this->assertSame('s3', config('filesystems.disks.s3.driver'));
    }

    public function test_storage_migrate_to_s3_command_is_registered(): void
    {
        $this->assertArrayHasKey('storage:migrate-to-s3', Artisan::all());
    }

    // -- Rows 6/8: §10.5's API leg — SMTP wire in, token API out --------------

    public function test_message_ingested_over_smtp_is_readable_via_the_token_api(): void
    {
        Storage::fake('local');
        $workspace = $this->installFresh();
        $inbox = $this->seedInbox($workspace);
        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->smtpAuthLoginSteps($inbox->smtp_username, $inbox->smtp_password),
            ['expect' => '/^235 /'],
            ['send' => "MAIL FROM:<alice@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.0 OK\r\n$/'],
            ['send' => "RCPT TO:<bob@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.5 OK\r\n$/'],
            ['send' => "DATA\r\n"],
            ['expect' => '/^354 /'],
            ['send' => implode("\r\n", [
                'From: Alice Sender <alice@example.com>',
                'To: Bob <bob@example.com>',
                'Subject: Slice seven end to end',
                'X-Sendtrap-Test-Id: slice7-e2e',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset="utf-8"',
                '',
                'Hi there',
                '',
            ])."\r\n.\r\n"],
            ['expect' => '/^250 2\.0\.0 Message accepted\r\n$/'],
        ]);

        // GET /api/v1/messages under the inbox's own token — the wire proof
        // that the package's token-authenticated API resolves cleanly under
        // Community's bindings (core has no Team concept, Plan 06 Phase 3
        // gate finding #1).
        $this->withHeader('Authorization', 'Bearer seeded-api-token')
            ->getJson('/api/v1/messages?test_id=slice7-e2e')
            ->assertOk()
            ->assertJsonPath('data.0.subject', 'Slice seven end to end')
            ->assertJsonPath('data.0.test_id', 'slice7-e2e');

        // POST /api/v1/assert with an instant (non-blocking) check.
        $this->withHeader('Authorization', 'Bearer seeded-api-token')
            ->postJson('/api/v1/assert', ['test_id' => 'slice7-e2e', 'subject_contains' => 'end to end'])
            ->assertOk()
            ->assertJsonPath('matched', true);
    }

    // -- Row 7: Mailtrap-compatible alias surface under a Community token -----

    public function test_mailtrap_compat_alias_answers_under_a_community_token(): void
    {
        Storage::fake('local');
        $workspace = $this->installFresh();
        $inbox = $this->seedInbox($workspace);
        $message = $this->seedMessage($inbox, 'Compat check');

        // The {sandbox} segment is accepted but ignored — the token is the
        // whole scope (package MailtrapCompatController).
        $this->withHeader('Authorization', 'Bearer seeded-api-token')
            ->getJson('/api/sandboxes/12345/messages')
            ->assertOk()
            ->assertJsonPath('0.subject', 'Compat check');

        $this->withHeader('Authorization', 'Bearer seeded-api-token')
            ->getJson("/api/sandboxes/12345/messages/{$message->id}/body.txt")
            ->assertOk();
    }

    // -- Row 21: web-auth happy paths (§10.4's success half; the
    //    registration-disabled half — /register 404, no Socialite routes —
    //    is pinned in Slice1SkeletonTest) ------------------------------------

    public function test_login_logout_round_trip(): void
    {
        $this->installFresh();
        $user = User::factory()->member()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_forgot_password_sends_a_reset_notification(): void
    {
        Notification::fake();
        $this->installFresh();
        $user = User::factory()->member()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo(
            $user,
            ResetPassword::class
        );
    }

    public function test_profile_information_and_password_update_happy_paths(): void
    {
        $this->installFresh();
        $user = User::factory()->member()->create();

        $this->actingAs($user)
            ->put('/user/profile-information', [
                'name' => 'Renamed Member',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed Member', $user->fresh()->name);

        $this->actingAs($user)
            ->put('/user/password', [
                'current_password' => 'password',
                'password' => 'new-password-1234',
                'password_confirmation' => 'new-password-1234',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(
            Hash::check('new-password-1234', $user->fresh()->password)
        );
    }

    public function test_two_factor_enable_and_confirm_happy_path(): void
    {
        $this->installFresh();
        $user = User::factory()->member()->create();

        // Fortify's confirmPassword mode: enabling 2FA sits behind the
        // password.confirm middleware; seed the confirmed-at session key
        // the same way a real confirm screen would.
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->withSession($confirmed)
            ->post('/user/two-factor-authentication')
            ->assertSessionHasNoErrors();

        $user = $user->fresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at, '2FA must not be active before confirmation');

        $otp = app(Google2FA::class)
            ->getCurrentOtp(decrypt($user->two_factor_secret));

        $this->actingAs($user)->withSession($confirmed)
            ->post('/user/confirmed-two-factor-authentication', ['code' => $otp])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    // -- Singleton context sanity for the prune path (row 5 mechanism) --------

    public function test_workspace_context_all_is_the_community_singleton_iterator_prune_relies_on(): void
    {
        $workspace = $this->installFresh();

        $context = app(WorkspaceContext::class);
        $this->assertInstanceOf(CommunityWorkspaceContext::class, $context);

        $all = iterator_to_array($context->all());
        $this->assertCount(1, $all);
        $this->assertTrue($all[0]->is($workspace));
    }
}
