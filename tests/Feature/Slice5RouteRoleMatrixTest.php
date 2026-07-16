<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Models\Workspace;
use Tests\CommunityTestCase;

/**
 * Slice 5 green criteria (Plan 06 Phase 4b design §11 slice 5): the H-5
 * domain route group is mounted, so the §10.3 owner/member/viewer role
 * matrix is now testable over REAL routes — the data provider is the §4.8
 * route→gate table. Also §10.9 at the route level (credential visibility
 * in the real dashboard/inbox Inertia payloads) and §10.11 (caniemail
 * HTML-Check works offline from the checked-in dataset).
 *
 * `messages.spam` is deliberately absent from the matrix: its 200 path
 * calls the Postmark SpamCheck service over the network (503 when
 * unavailable), so a status assertion would be network-dependent — the
 * gate it carries (`view`) is the same one the other read tabs pin.
 */
class Slice5RouteRoleMatrixTest extends CommunityTestCase
{
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

    /**
     * @return array{0: Workspace, 1: Project, 2: Inbox, 3: Message, 4: Attachment}
     */
    private function seedDomain(): array
    {
        Storage::fake('local');

        $workspace = $this->installFresh();
        $project = $workspace->projects()->create(['name' => 'Project One']);

        $inbox = $project->inboxes()->create([
            'name' => 'Inbox One',
            'smtp_username' => 'seeded-smtp-username',
            'smtp_password' => 'seeded-smtp-password',
            'api_token' => 'seeded-api-token',
        ]);

        // An active inbox share — its token is a bearer-usable string the
        // §4.7/§10.9 credential gate must hide from viewers.
        $inbox->shares()->create([
            'token' => 'seeded-inbox-share-token',
            'expires_at' => now()->addDays(7),
        ]);

        $raw = $this->sampleRawMessage();
        Storage::disk('local')->put('messages/slice5.eml', $raw);

        $message = $inbox->messages()->create([
            'message_id' => 'slice5@example.com',
            'from_address' => 'alice@example.com',
            'from_name' => 'Alice Sender',
            'to' => [['address' => 'bob@example.com', 'name' => 'Bob']],
            'cc' => [],
            'subject' => 'Slice five wire check',
            'size' => strlen($raw),
            'is_read' => false,
            'has_html' => true,
            'has_text' => true,
            'has_attachments' => true,
            'raw_path' => 'messages/slice5.eml',
            'received_at' => now(),
        ]);

        Storage::disk('local')->put('attachments/slice5.txt', 'attachment-bytes');

        $attachment = $message->attachments()->create([
            'filename' => 'slice5.txt',
            'content_type' => 'text/plain',
            'size' => 16,
            'path' => 'attachments/slice5.txt',
        ]);

        return [$workspace, $project, $inbox, $message, $attachment];
    }

    private function sampleRawMessage(): string
    {
        return implode("\r\n", [
            'From: Alice Sender <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Subject: Slice five wire check',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="slice5boundary"',
            '',
            '--slice5boundary',
            'Content-Type: text/plain; charset="utf-8"',
            '',
            'Plain body',
            '--slice5boundary',
            'Content-Type: text/html; charset="utf-8"',
            '',
            '<html><body><p style="color: #ff0000">Hello from slice five</p></body></html>',
            '--slice5boundary--',
            '',
        ]);
    }

    // -- §10.3 the owner/member/viewer matrix over the §4.8 route table ----

    /**
     * @return array<string, array{string, string, string, list<string>, array<string, mixed>, int}>
     */
    public static function roleMatrix(): array
    {
        return [
            // Viewer: pure reads → 200 (§4.4)
            'viewer GET dashboard' => ['viewer', 'get', 'dashboard', [], [], 200],
            'viewer GET inboxes.show' => ['viewer', 'get', 'inboxes.show', ['inbox'], [], 200],
            'viewer GET messages.index' => ['viewer', 'get', 'messages.index', ['inbox'], [], 200],
            'viewer GET messages.show' => ['viewer', 'get', 'messages.show', ['message'], [], 200],
            'viewer GET messages.raw' => ['viewer', 'get', 'messages.raw', ['message'], [], 200],
            'viewer GET messages.html' => ['viewer', 'get', 'messages.html', ['message'], [], 200],
            'viewer GET messages.htmlcheck' => ['viewer', 'get', 'messages.htmlcheck', ['message'], [], 200],
            'viewer GET messages.attachment' => ['viewer', 'get', 'messages.attachment', ['message', 'attachment'], [], 200],
            // Viewer: per-message mark-read is allowed (§4.4, deliberate)
            'viewer PATCH messages.read' => ['viewer', 'patch', 'messages.read', ['message'], [], 302],
            // Viewer: every mutation / management surface → 403
            'viewer GET inboxes.settings' => ['viewer', 'get', 'inboxes.settings', ['inbox'], [], 403],
            'viewer POST projects.store (F2)' => ['viewer', 'post', 'projects.store', [], ['name' => 'Nope'], 403],
            'viewer PUT projects.update' => ['viewer', 'put', 'projects.update', ['project'], [], 403],
            'viewer DELETE projects.destroy' => ['viewer', 'delete', 'projects.destroy', ['project'], [], 403],
            'viewer POST inboxes.store' => ['viewer', 'post', 'inboxes.store', ['project'], ['name' => 'Nope'], 403],
            'viewer PUT inboxes.update' => ['viewer', 'put', 'inboxes.update', ['inbox'], [], 403],
            'viewer POST inboxes.read-all (§4.4 asymmetry)' => ['viewer', 'post', 'inboxes.read-all', ['inbox'], [], 403],
            'viewer POST inboxes.clear' => ['viewer', 'post', 'inboxes.clear', ['inbox'], [], 403],
            'viewer DELETE inboxes.destroy' => ['viewer', 'delete', 'inboxes.destroy', ['inbox'], [], 403],
            'viewer POST inboxes.share (§4.5)' => ['viewer', 'post', 'inboxes.share', ['inbox'], ['days' => 7], 403],
            'viewer DELETE inboxes.share.destroy (§4.5)' => ['viewer', 'delete', 'inboxes.share.destroy', ['inbox'], [], 403],
            'viewer POST messages.share (§4.5)' => ['viewer', 'post', 'messages.share', ['message'], [], 403],
            'viewer DELETE messages.destroy' => ['viewer', 'delete', 'messages.destroy', ['message'], [], 403],

            // Member: reads + every email-domain mutation succeed
            'member GET dashboard' => ['member', 'get', 'dashboard', [], [], 200],
            'member GET inboxes.show' => ['member', 'get', 'inboxes.show', ['inbox'], [], 200],
            'member GET inboxes.settings' => ['member', 'get', 'inboxes.settings', ['inbox'], [], 200],
            'member POST projects.store (F2)' => ['member', 'post', 'projects.store', [], ['name' => 'Member Project'], 302],
            'member PUT projects.update' => ['member', 'put', 'projects.update', ['project'], ['name' => 'Renamed'], 302],
            'member POST inboxes.store' => ['member', 'post', 'inboxes.store', ['project'], ['name' => 'Member Inbox'], 302],
            'member PUT inboxes.update' => ['member', 'put', 'inboxes.update', ['inbox'], ['name' => 'Renamed', 'max_messages' => 100], 302],
            'member POST inboxes.read-all' => ['member', 'post', 'inboxes.read-all', ['inbox'], [], 302],
            'member POST inboxes.clear' => ['member', 'post', 'inboxes.clear', ['inbox'], [], 302],
            'member POST inboxes.share' => ['member', 'post', 'inboxes.share', ['inbox'], ['days' => 7], 302],
            'member DELETE inboxes.share.destroy' => ['member', 'delete', 'inboxes.share.destroy', ['inbox'], [], 302],
            'member POST messages.share' => ['member', 'post', 'messages.share', ['message'], [], 302],
            'member PATCH messages.read' => ['member', 'patch', 'messages.read', ['message'], [], 302],
            'member DELETE messages.destroy' => ['member', 'delete', 'messages.destroy', ['message'], [], 302],
            'member DELETE inboxes.destroy' => ['member', 'delete', 'inboxes.destroy', ['inbox'], [], 302],
            'member DELETE projects.destroy' => ['member', 'delete', 'projects.destroy', ['project'], [], 302],

            // Owner: everything member has (users/settings land in slice 6)
            'owner GET dashboard' => ['owner', 'get', 'dashboard', [], [], 200],
            'owner GET inboxes.show' => ['owner', 'get', 'inboxes.show', ['inbox'], [], 200],
            'owner GET inboxes.settings' => ['owner', 'get', 'inboxes.settings', ['inbox'], [], 200],
            'owner POST projects.store' => ['owner', 'post', 'projects.store', [], ['name' => 'Owner Project'], 302],
            'owner PUT projects.update' => ['owner', 'put', 'projects.update', ['project'], ['name' => 'Renamed'], 302],
            'owner POST inboxes.store' => ['owner', 'post', 'inboxes.store', ['project'], ['name' => 'Owner Inbox'], 302],
            'owner PUT inboxes.update' => ['owner', 'put', 'inboxes.update', ['inbox'], ['name' => 'Renamed', 'max_messages' => 100], 302],
            'owner POST inboxes.read-all' => ['owner', 'post', 'inboxes.read-all', ['inbox'], [], 302],
            'owner POST inboxes.clear' => ['owner', 'post', 'inboxes.clear', ['inbox'], [], 302],
            'owner POST inboxes.share' => ['owner', 'post', 'inboxes.share', ['inbox'], ['days' => 7], 302],
            'owner DELETE inboxes.share.destroy' => ['owner', 'delete', 'inboxes.share.destroy', ['inbox'], [], 302],
            'owner POST messages.share' => ['owner', 'post', 'messages.share', ['message'], [], 302],
            'owner PATCH messages.read' => ['owner', 'patch', 'messages.read', ['message'], [], 302],
            'owner DELETE messages.destroy' => ['owner', 'delete', 'messages.destroy', ['message'], [], 302],
            'owner DELETE inboxes.destroy' => ['owner', 'delete', 'inboxes.destroy', ['inbox'], [], 302],
            'owner DELETE projects.destroy' => ['owner', 'delete', 'projects.destroy', ['project'], [], 302],
        ];
    }

    /**
     * @param  list<string>  $paramKeys
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('roleMatrix')]
    public function test_role_matrix_over_real_routes(
        string $role,
        string $method,
        string $routeName,
        array $paramKeys,
        array $payload,
        int $expected,
    ): void {
        [, $project, $inbox, $message, $attachment] = $this->seedDomain();

        $refs = compact('project', 'inbox', 'message', 'attachment');
        $params = [];
        foreach ($paramKeys as $key) {
            $params[$key] = $refs[$key];
        }

        $user = User::factory()->{$role}()->create();
        $url = route($routeName, $params);

        $response = $method === 'get'
            ? $this->actingAs($user)->get($url)
            : $this->actingAs($user)->{$method}($url, $payload);

        $this->assertSame(
            $expected,
            $response->getStatusCode(),
            "{$role} ".strtoupper($method)." {$routeName} expected {$expected}, got {$response->getStatusCode()}",
        );
    }

    public function test_unauthenticated_requests_are_redirected_to_login(): void
    {
        [, , $inbox] = $this->seedDomain();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('inboxes.show', $inbox))->assertRedirect(route('login'));
    }

    // -- §10.9 credential visibility in the real Inertia payloads ----------

    public function test_viewer_payloads_contain_no_bearer_usable_credential_strings(): void
    {
        [, , $inbox] = $this->seedDomain();
        $viewer = User::factory()->viewer()->create();

        foreach ([route('dashboard'), route('inboxes.show', $inbox)] as $url) {
            $body = $this->actingAs($viewer)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('seeded-smtp-username', $body, $url);
            $this->assertStringNotContainsString('seeded-smtp-password', $body, $url);
            $this->assertStringNotContainsString('seeded-api-token', $body, $url);
            $this->assertStringNotContainsString('seeded-inbox-share-token', $body, $url);
        }
    }

    public function test_member_and_owner_payloads_contain_the_credentials(): void
    {
        [, , $inbox] = $this->seedDomain();

        foreach ([User::factory()->member()->create(), User::factory()->owner()->create()] as $user) {
            // Dashboard payload: the three integration credentials (the
            // active share URL is only serialised when the shares relation
            // is loaded, which the dashboard listing doesn't do).
            $dashboard = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
            $this->assertStringContainsString('seeded-smtp-username', $dashboard);
            $this->assertStringContainsString('seeded-smtp-password', $dashboard);
            $this->assertStringContainsString('seeded-api-token', $dashboard);

            // Inbox view payload: all four, including the active share URL.
            $show = $this->actingAs($user)->get(route('inboxes.show', $inbox))->assertOk()->getContent();
            $this->assertStringContainsString('seeded-smtp-username', $show);
            $this->assertStringContainsString('seeded-smtp-password', $show);
            $this->assertStringContainsString('seeded-api-token', $show);
            $this->assertStringContainsString('seeded-inbox-share-token', $show);
        }
    }

    // -- §10.11 caniemail HTML-Check offline from the checked-in snapshot --

    public function test_html_check_returns_a_numeric_ratio_offline_from_the_checked_in_dataset(): void
    {
        $this->assertFileExists(resource_path('data/caniemail/features.json'));

        [, , , $message] = $this->seedDomain();
        $viewer = User::factory()->viewer()->create();

        $response = $this->actingAs($viewer)
            ->get(route('messages.htmlcheck', $message))
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertIsNumeric($response->json('compatibility_ratio'));
    }

    // -- §4.8 route-shape guards -------------------------------------------

    public function test_share_mutation_routes_carry_the_manage_workspace_gate(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['messages.share', 'inboxes.share', 'inboxes.share.destroy'] as $name) {
            $route = $routes->getByName($name);
            $this->assertNotNull($route, "route {$name} must exist");
            $this->assertContains(
                'can:manage-workspace',
                $route->gatherMiddleware(),
                "{$name} must carry the can:manage-workspace middleware (§4.5/§4.8)",
            );
        }
    }
}
