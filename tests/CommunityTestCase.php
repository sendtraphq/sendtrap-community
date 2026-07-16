<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base test case for Community's own host-side suite. RefreshDatabase
 * migrates fresh for every test — both Community's own migrations and
 * sendtrap/core's package migrations (loaded via
 * SendtrapCoreServiceProvider::boot()'s loadMigrationsFrom()), proving
 * the package's migrations run cleanly from empty under Community's own
 * bindings, not just the package's own Testbench harness.
 */
abstract class CommunityTestCase extends TestCase
{
    use RefreshDatabase;
}
