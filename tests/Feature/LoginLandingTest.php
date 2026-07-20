<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\InstanceSettings;
use Illuminate\Testing\TestResponse;
use Sendtrap\Core\Models\Workspace;
use Tests\CommunityTestCase;

/**
 * Post-login landing (App\Http\Responses\LoginResponse): a single-inbox
 * instance — the fresh-install starter shape — lands on the inbox page,
 * where the full SMTP/API credential panel is visible; anything else falls
 * back to the dashboard.
 */
class LoginLandingTest extends CommunityTestCase
{
    private function makeWorkspace(): Workspace
    {
        $workspace = Workspace::factory()->create();
        InstanceSettings::put('workspace_id', $workspace->id);

        return $workspace;
    }

    private function login(): TestResponse
    {
        $user = User::factory()->owner()->create(['password' => bcrypt('password1234')]);

        return $this->post('/login', [
            'email' => $user->email,
            'password' => 'password1234',
        ]);
    }

    public function test_login_on_a_single_inbox_instance_lands_on_the_inbox_page(): void
    {
        $workspace = $this->makeWorkspace();
        $project = $workspace->projects()->create(['name' => 'My app']);
        $inbox = $project->inboxes()->create(['name' => 'Testing']);

        $this->login()->assertRedirect(route('inboxes.show', $inbox));
    }

    public function test_login_with_several_inboxes_lands_on_the_dashboard(): void
    {
        $workspace = $this->makeWorkspace();
        $project = $workspace->projects()->create(['name' => 'My app']);
        $project->inboxes()->create(['name' => 'Testing']);
        $project->inboxes()->create(['name' => 'Staging']);

        $this->login()->assertRedirect('/dashboard');
    }

    public function test_login_still_honours_an_intended_url(): void
    {
        $workspace = $this->makeWorkspace();
        $workspace->projects()->create(['name' => 'My app'])->inboxes()->create(['name' => 'Testing']);

        // A guest deep-link records an intended URL; the landing default
        // must not override it.
        $this->get('/dashboard')->assertRedirect('/login');

        $this->login()->assertRedirect('/dashboard');
    }
}
