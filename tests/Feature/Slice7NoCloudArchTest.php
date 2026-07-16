<?php

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Plan 06 Phase 4b design §10.7 — the no-Cloud-references arch test (the
 * "no Cloud references" clause of the Phase 4 exit gate). A plain
 * filesystem scan (no Pest arch plugin); scan roots per the design:
 * `app/`, `config/`, `routes/`, `resources/js/`, `composer.lock`, and the
 * package migrations directory Community runs.
 *
 * Five clauses, each its own test so a violation names its root:
 *
 *  1. composer.lock carries none of laravel/cashier, laravel/socialite,
 *     laravel/jetstream, stripe/*.
 *  2. No file under app/, config/ or routes/ contains the Cloud tokens
 *     (App\Cloud, TeamPlan, SendingLimiter, belongsToTeam, currentTeam,
 *     billing, Cashier, Socialite). Raw substring scan, case-sensitive,
 *     comments included — Community-authored comments must stay
 *     token-clean too, so a grep of the shipped tree is unambiguous.
 *  3. No file under resources/js contains route('teams. / route('billing. /
 *     teams.show / billing.show / current-team — catches a stripped
 *     nav/controller shell still emitting a Cloud route name (complements
 *     the §10.8 Ziggy smoke, which only sees executable route() calls).
 *  4. No config/billing.php / config/jetstream.php / config/cashier.php.
 *  5. No package migration Community runs contains a ('team_id' or
 *     ['team_id' schema token AFTER comment-stripping (§9.3: the package
 *     migrations legitimately mention team_id in prose comments — 6
 *     comment hits verified in the design, one of them itself matching
 *     ('team_id' — so a raw substring grep is wrong; the executable-code
 *     token is what would make core's `projects` table Cloud-shaped, which
 *     it must never be — core has no Team concept at all, Plan 06 Phase 3
 *     gate finding #1).
 */
class Slice7NoCloudArchTest extends TestCase
{
    /**
     * §10.7 token list for the PHP roots, verbatim from the design.
     *
     * @var list<string>
     */
    private const PHP_ROOT_TOKENS = [
        'App\\Cloud',
        'TeamPlan',
        'SendingLimiter',
        'belongsToTeam',
        'currentTeam',
        'billing',
        'Cashier',
        'Socialite',
    ];

    /**
     * §10.7 token list for resources/js.
     *
     * @var list<string>
     */
    private const JS_ROOT_TOKENS = [
        "route('teams.",
        "route('billing.",
        'teams.show',
        'billing.show',
        'current-team',
    ];

    /**
     * Package names that must not appear anywhere in composer.lock.
     *
     * @var list<string>
     */
    private const FORBIDDEN_PACKAGES = [
        'laravel/cashier',
        'laravel/socialite',
        'laravel/jetstream',
        '"stripe/',
    ];

    public function test_composer_lock_contains_no_cloud_only_packages(): void
    {
        $lock = file_get_contents(base_path('composer.lock'));

        $this->assertNotFalse($lock, 'composer.lock must exist and be readable');

        foreach (self::FORBIDDEN_PACKAGES as $package) {
            $this->assertFalse(
                str_contains($lock, $package),
                "composer.lock must not reference {$package} (Cloud-only dependency)"
            );
        }
    }

    public function test_no_php_root_file_contains_a_cloud_token(): void
    {
        $violations = [];

        foreach (['app', 'config', 'routes'] as $root) {
            foreach ($this->filesUnder(base_path($root)) as $file) {
                $contents = file_get_contents($file->getPathname());

                foreach (self::PHP_ROOT_TOKENS as $token) {
                    if (str_contains($contents, $token)) {
                        $violations[] = "{$file->getPathname()} contains \"{$token}\"";
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Cloud tokens found:\n".implode("\n", $violations));
    }

    public function test_no_resources_js_file_references_a_cloud_route_name(): void
    {
        $violations = [];

        foreach ($this->filesUnder(base_path('resources/js')) as $file) {
            $contents = file_get_contents($file->getPathname());

            foreach (self::JS_ROOT_TOKENS as $token) {
                if (str_contains($contents, $token)) {
                    $violations[] = "{$file->getPathname()} contains \"{$token}\"";
                }
            }
        }

        $this->assertSame([], $violations, "Cloud route tokens found:\n".implode("\n", $violations));
    }

    public function test_no_cloud_only_config_file_exists(): void
    {
        foreach (['billing.php', 'jetstream.php', 'cashier.php'] as $file) {
            $this->assertFileDoesNotExist(
                base_path('config/'.$file),
                "config/{$file} is Cloud-only and must not exist in Community"
            );
        }
    }

    /**
     * §9.3 / §10.7 refined guard: comment-strip each package migration
     * Community runs, then assert no ('team_id' / ['team_id' schema token
     * remains in the executable code. Load-bearing, not a formality: core's
     * own `projects` table has no `team_id` column at all (§7.3) — a real
     * one appearing here would mean a Cloud-only schema change leaked into
     * the shared package migrations Community also runs.
     */
    public function test_package_migrations_have_no_team_id_schema_token_after_comment_stripping(): void
    {
        $dir = base_path('vendor/sendtrap/core/database/migrations');

        $this->assertDirectoryExists($dir, 'package migrations directory must be present');

        $migrations = glob($dir.'/*.php');
        $this->assertNotEmpty($migrations, 'expected at least one package migration to scan');

        $violations = [];

        foreach ($migrations as $migration) {
            $code = $this->stripComments(file_get_contents($migration));

            foreach (["('team_id'", "['team_id'"] as $token) {
                if (str_contains($code, $token)) {
                    $violations[] = basename($migration)." contains {$token} outside comments";
                }
            }
        }

        $this->assertSame([], $violations, "team_id schema tokens found:\n".implode("\n", $violations));
    }

    /**
     * Remove line comments and block comments (docblocks included) from
     * PHP source using the real tokenizer, so string literals and code are
     * never mangled by a regex approximation.
     */
    private function stripComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $stripped .= $token[1];
            } else {
                $stripped .= $token;
            }
        }

        return $stripped;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function filesUnder(string $root): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield $file;
            }
        }
    }
}
