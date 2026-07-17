<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sendtrap\Core\Models\Workspace;
use Tests\TestCase;

/**
 * The root route (roadmap quick win #9): a quickstart checklist while the
 * install is unfinished, a redirect to login once the workspace and the
 * first owner exist.
 */
class QuickstartPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_uninstalled_instance_shows_the_quickstart_checklist(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('sendtrap:install')
            ->assertSee('mail:smtp-server')
            ->assertSee('sendtrap:send-test');
    }

    public function test_an_installed_instance_redirects_to_login(): void
    {
        Workspace::factory()->create();
        $owner = User::factory()->create();
        $owner->role = Role::Owner;
        $owner->save();

        $this->get('/')->assertRedirect(route('login'));
    }
}
