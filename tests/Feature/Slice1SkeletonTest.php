<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Sendtrap\Core\SendtrapCoreServiceProvider;
use Tests\CommunityTestCase;

/**
 * Slice 1 green criteria (Plan 06 Phase 4b design §11 slice 1, plus the
 * host-requirement wiring pulled forward into this slice): the app boots,
 * sendtrap/core's service provider is loaded and its migrations run clean
 * from empty, its share/API routes are mounted and behave correctly
 * rather than 500ing, and registration is confirmed absent.
 */
class Slice1SkeletonTest extends CommunityTestCase
{
    public function test_the_application_boots(): void
    {
        $this->get('/')->assertSuccessful();
    }

    public function test_the_package_service_provider_is_loaded(): void
    {
        $this->assertTrue(
            $this->app->getLoadedProviders()[SendtrapCoreServiceProvider::class] ?? false,
            'Sendtrap\Core\SendtrapCoreServiceProvider was not loaded — package auto-discovery failed.',
        );
    }

    public function test_package_migrations_ran_from_empty(): void
    {
        // Package-owned tables (Sendtrap\Core\...\database\migrations),
        // never copied into Community's own database/migrations.
        foreach (['workspaces', 'projects', 'inboxes', 'messages', 'attachments', 'message_shares', 'inbox_shares'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Package migration for `{$table}` did not run.");
        }
    }

    public function test_community_migrations_ran(): void
    {
        // Community's own skeleton + Fortify migrations.
        foreach (['users', 'sessions', 'cache', 'jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Community migration for `{$table}` did not run.");
        }
        $this->assertTrue(
            Schema::hasColumn('users', 'two_factor_secret'),
            'Fortify two-factor columns were not migrated onto users.',
        );
    }

    public function test_dashboard_route_exists_and_is_auth_gated(): void
    {
        // Host requirement (package docblock item 3): a route literally
        // named `dashboard` must exist. Slice 1 satisfied it with an open
        // placeholder; slice 5 replaced that with the real workspace-backed
        // ProjectController@index behind `auth` (§4.8), so a guest is now
        // redirected to login rather than served content.
        $this->assertTrue(app('router')->getRoutes()->hasNamedRoute('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_share_route_with_an_unknown_token_is_404_not_500(): void
    {
        // The design's own slice-1 green criterion: a package share route
        // must 404 on an unknown token, never 500 (which would mean the
        // package's migrations didn't run, or the route wasn't mounted).
        $this->get('/share/this-token-does-not-exist')->assertNotFound();
    }

    public function test_inbox_share_route_with_an_unknown_token_is_404_not_500(): void
    {
        $this->get('/share/inbox/this-token-does-not-exist')->assertNotFound();
    }

    public function test_token_api_route_without_a_token_is_401_not_500(): void
    {
        // Proves the inbox-api rate limiter is registered (a missing name
        // throws MissingRateLimiterException = 500, independent of whether
        // a token is present) and that AuthenticateInboxToken's own
        // "token missing" branch short-circuits before resolve() is ever
        // called (that only matters once a token is supplied).
        $this->getJson('/api/v1/messages')->assertUnauthorized();
    }

    public function test_token_api_route_with_an_invalid_token_is_401_not_500(): void
    {
        // Exercises AuthenticateInboxToken::resolve()'s project.workspace
        // eager load (core has no Team concept and no NullTeam resolver to
        // register any more, Plan 06 Phase 3 gate finding #1) — an
        // invalid-but-present token still reaches the "invalid token" 401
        // branch instead of throwing.
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/messages')
            ->assertUnauthorized();
    }

    public function test_registration_routes_do_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_login_route_is_registered(): void
    {
        // Fortify registers this because Features::resetPasswords() etc.
        // are enabled. The Blade/Inertia login page itself is authored in
        // a later slice (§7.3 of the design), so this only checks the
        // route exists, not that it renders.
        $this->assertTrue(Route::has('login'));
    }

    public function test_no_socialite_routes_exist(): void
    {
        $this->get('/auth/github/redirect')->assertNotFound();
    }
}
