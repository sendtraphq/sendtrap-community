<?php

namespace Tests\Feature;

use App\Support\CommunityEntitlements;
use App\Support\CommunityUsageMeter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Testing\Concerns\InteractsWithSmtpServer;
use Tests\CommunityTestCase;

/**
 * Slice 4 green criteria (Plan 06 Phase 4b design §11 slice 4): entitlement
 * + D-17 tests (§10.6) plus the SMTP-to-API end-to-end (§10.5) now passing
 * end to end under Community's real Entitlements/UsageMeter bindings.
 */
class Slice4EntitlementsAndUsageMeterTest extends CommunityTestCase
{
    use InteractsWithSmtpServer;

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

    // -- Bindings ----------------------------------------------------------

    public function test_entitlements_and_usage_meter_are_bound_to_the_community_implementations(): void
    {
        $this->assertInstanceOf(CommunityEntitlements::class, app(Entitlements::class));
        $this->assertInstanceOf(CommunityUsageMeter::class, app(UsageMeter::class));
    }

    // -- §5.1 default config + parity flags ---------------------------------

    public function test_every_limit_getter_is_unlimited_when_config_is_absent(): void
    {
        $workspace = $this->installFresh();
        $ent = app(Entitlements::class)->for($workspace);

        $this->assertNull($ent->sendsPerMinute());
        $this->assertNull($ent->sendsPerMonth());
        $this->assertNull($ent->forwardsPerMonth());
        $this->assertNull($ent->emailSizeBytes());
        $this->assertNull($ent->projectsLimit());
        $this->assertNull($ent->inboxesLimit());
        $this->assertNull($ent->usersLimit());
        $this->assertNull($ent->messagesPerInbox());
        $this->assertNull($ent->retentionDays());
        $this->assertNull($ent->storageBytesLimit());
        $this->assertNull($ent->apiRequestsPerMinute());
    }

    public function test_parity_flags_match_the_community_matrix(): void
    {
        $workspace = $this->installFresh();
        $ent = app(Entitlements::class)->for($workspace);

        // Parity row 6: HTML-Check API ships ungated in Community.
        $this->assertTrue($ent->hasApiAccess());
        $this->assertTrue($ent->hasHtmlCheckApi());
        // Parity row 26: no in-app support form.
        $this->assertFalse($ent->hasSupport());
    }

    // -- §5.2 D-17: absent = unlimited, 0 = blocked -------------------------

    public function test_d17_absent_forwards_per_month_is_unlimited(): void
    {
        config(['sendtrap-community.limits.forwards_per_month' => null]);
        $workspace = $this->installFresh();

        $this->assertTrue(app(UsageMeter::class)->canForward($workspace));
        // Hammer it a few times — still never blocked with no configured limit.
        for ($i = 0; $i < 5; $i++) {
            app(UsageMeter::class)->recordForward($workspace);
        }
        $this->assertTrue(app(UsageMeter::class)->canForward($workspace));
    }

    public function test_d17_zero_forwards_per_month_blocks_forwarding_entirely(): void
    {
        config(['sendtrap-community.limits.forwards_per_month' => 0]);
        $workspace = $this->installFresh();

        $this->assertFalse(app(UsageMeter::class)->canForward($workspace));
    }

    // -- §5.1 within(): advisory projects/inboxes/users limits --------------

    public function test_within_projects_inboxes_users_respects_configured_advisory_limits(): void
    {
        config([
            'sendtrap-community.limits.projects' => 2,
            'sendtrap-community.limits.inboxes' => 1,
            'sendtrap-community.limits.users' => 0,
        ]);
        $workspace = $this->installFresh();
        $ent = app(Entitlements::class)->for($workspace);

        $this->assertTrue($ent->within('projects', 1));
        $this->assertFalse($ent->within('projects', 2));

        $this->assertTrue($ent->within('inboxes', 0));
        $this->assertFalse($ent->within('inboxes', 1));

        // D-17: a configured 0 blocks outright, at any current count.
        $this->assertFalse($ent->within('users', 0));
    }

    public function test_within_is_always_true_for_an_unconfigured_advisory_limit(): void
    {
        $workspace = $this->installFresh();
        $ent = app(Entitlements::class)->for($workspace);

        $this->assertTrue($ent->within('projects', 999));
        $this->assertTrue($ent->within('inboxes', 999));
        $this->assertTrue($ent->within('users', 999));
    }

    // -- §5.3 summary() exact shape ------------------------------------------

    public function test_summary_shape_with_no_limits_configured(): void
    {
        $workspace = $this->installFresh();

        $summary = app(UsageMeter::class)->summary($workspace);

        $this->assertSame(
            ['per_minute', 'per_month', 'month_usage', 'pct', 'recent_block'],
            array_keys($summary)
        );
        $this->assertNull($summary['per_minute']);
        $this->assertNull($summary['per_month']);
        $this->assertSame(0, $summary['month_usage']);
        $this->assertSame(0, $summary['pct']);
        $this->assertNull($summary['recent_block']);
    }

    public function test_summary_reflects_configured_sends_per_month_and_recorded_usage(): void
    {
        config(['sendtrap-community.limits.sends_per_month' => 2]);
        $workspace = $this->installFresh();
        $meter = app(UsageMeter::class);

        $meter->recordSend($workspace);
        $meter->recordSend($workspace);

        $summary = $meter->summary($workspace);

        $this->assertSame(2, $summary['per_month']);
        $this->assertSame(2, $summary['month_usage']);
        $this->assertSame(100, $summary['pct']);
    }

    public function test_check_send_reports_quota_and_records_a_recent_block(): void
    {
        config(['sendtrap-community.limits.sends_per_month' => 1]);
        $workspace = $this->installFresh();
        $meter = app(UsageMeter::class);

        $this->assertNull($meter->checkSend($workspace));
        $meter->recordSend($workspace);

        $this->assertSame('quota', $meter->checkSend($workspace));
        $this->assertSame('quota', $meter->summary($workspace)['recent_block']);
    }

    public function test_check_send_reports_rate_when_the_per_minute_ceiling_is_reached(): void
    {
        $this->travelTo(now());
        config([
            'sendtrap-community.limits.sends_per_minute' => 1,
            'sendtrap-community.limits.sends_per_month' => 1000,
        ]);
        $workspace = $this->installFresh();
        $meter = app(UsageMeter::class);

        $this->assertNull($meter->checkSend($workspace));
        $meter->recordSend($workspace);

        $this->assertSame('rate', $meter->checkSend($workspace));
    }

    // -- §5.3 storage aggregation --------------------------------------------

    public function test_storage_aggregation_sums_message_and_attachment_bytes_across_the_workspace(): void
    {
        Storage::fake('local');
        $workspace = $this->installFresh();
        $inboxA = $this->seedInbox($workspace);
        $projectB = $workspace->projects()->create(['name' => 'Project Two']);
        $inboxB = $projectB->inboxes()->create(['name' => 'Inbox Two']);

        $messageA = Message::factory()->create(['inbox_id' => $inboxA->id, 'size' => 1000]);
        Attachment::factory()->create(['message_id' => $messageA->id, 'size' => 250]);
        Message::factory()->create(['inbox_id' => $inboxB->id, 'size' => 500]);

        $this->assertSame(1750, app(UsageMeter::class)->currentStorageBytes($workspace));
    }

    public function test_would_exceed_storage_is_false_when_no_limit_is_configured(): void
    {
        Storage::fake('local');
        $workspace = $this->installFresh();

        $this->assertFalse(app(UsageMeter::class)->wouldExceedStorage($workspace, 1_000_000_000));
    }

    public function test_would_exceed_storage_respects_a_configured_limit(): void
    {
        Storage::fake('local');
        config(['sendtrap-community.limits.storage_bytes' => 1000]);
        $workspace = $this->installFresh();
        $inbox = $this->seedInbox($workspace);
        Message::factory()->create(['inbox_id' => $inbox->id, 'size' => 900]);

        $meter = app(UsageMeter::class);

        $this->assertFalse($meter->wouldExceedStorage($workspace, 50));
        $this->assertTrue($meter->wouldExceedStorage($workspace, 200));
    }

    // -- §10.5/§10.6 SMTP-to-API end-to-end under real bindings -------------

    private function sampleRawMessage(): string
    {
        return implode("\r\n", [
            'From: Alice Sender <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Subject: Hello from the wire',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset="utf-8"',
            '',
            'Hi there',
            '',
        ]);
    }

    /**
     * @return array<int, array{send?: string, expect?: string}>
     */
    private function conversation(object $inbox, array $tail): array
    {
        return [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->smtpAuthLoginSteps($inbox->smtp_username, $inbox->smtp_password),
            ['expect' => '/^235 /'],
            ...$tail,
        ];
    }

    /**
     * @return array<int, array{send?: string, expect?: string}>
     */
    private function sendSteps(string $expect): array
    {
        return [
            ['send' => "MAIL FROM:<alice@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.0 OK\r\n$/'],
            ['send' => "RCPT TO:<bob@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.5 OK\r\n$/'],
            ['send' => "DATA\r\n"],
            ['expect' => '/^354 /'],
            ['send' => $this->sampleRawMessage()."\r\n.\r\n"],
            ['expect' => $expect],
        ];
    }

    public function test_smtp_accepts_a_send_under_limits_and_the_message_is_ingested(): void
    {
        Storage::fake('local');
        $workspace = $this->installFresh();
        $inbox = $this->seedInbox($workspace);
        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, $this->conversation(
            $inbox,
            $this->sendSteps('/^250 2\.0\.0 Message accepted\r\n$/')
        ));

        $this->assertSame(1, Message::where('inbox_id', $inbox->id)->count());
        $this->assertSame(1, app(UsageMeter::class)->monthCount($workspace));
    }

    /**
     * Design §10.6 item 6: sends_per_month = 2, the 3rd send at DATA is
     * rejected with SMTP 550, and summary()['per_month'] === 2.
     */
    public function test_smtp_rejects_the_third_send_with_550_once_the_monthly_quota_is_reached(): void
    {
        Storage::fake('local');
        config(['sendtrap-community.limits.sends_per_month' => 2]);
        $workspace = $this->installFresh();
        $inbox = $this->seedInbox($workspace);
        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, $this->conversation($inbox, [
            ...$this->sendSteps('/^250 2\.0\.0 Message accepted\r\n$/'),
            ...$this->sendSteps('/^250 2\.0\.0 Message accepted\r\n$/'),
            ...$this->sendSteps('/^550 5\.7\.0 Monthly sending quota exceeded\r\n$/'),
        ]));

        $this->assertSame(2, Message::where('inbox_id', $inbox->id)->count());
        $this->assertSame(2, app(UsageMeter::class)->summary($workspace)['per_month']);
        $this->assertSame(2, app(UsageMeter::class)->summary($workspace)['month_usage']);
    }

    public function test_smtp_rejects_with_452_once_the_per_minute_rate_is_reached(): void
    {
        $this->travelTo(now());
        Storage::fake('local');
        config([
            'sendtrap-community.limits.sends_per_minute' => 1,
            'sendtrap-community.limits.sends_per_month' => 1000,
        ]);
        $workspace = $this->installFresh();
        $inbox = $this->seedInbox($workspace);
        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, $this->conversation($inbox, [
            ...$this->sendSteps('/^250 2\.0\.0 Message accepted\r\n$/'),
            ...$this->sendSteps('/^452 4\.2\.1 Rate limit exceeded, please slow down\r\n$/'),
        ]));

        $this->assertSame(1, Message::where('inbox_id', $inbox->id)->count());
    }
}
