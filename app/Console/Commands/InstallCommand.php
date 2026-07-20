<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Models\Workspace;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * One-shot installer: migrate, create the single workspace, create the
 * first owner user (via Laravel Prompts, or flags for non-interactive use).
 *
 * Idempotent by contract: run any number of times, this converges to
 * exactly one Workspace and exactly one (the first) owner user. Migrations
 * are append-only and already-run ones are skipped by basename, workspace
 * creation is existence-guarded, and owner creation is skipped once any
 * user exists.
 *
 * The first user created here is explicitly given `role = 'owner'` — never
 * the `users.role` column default (`viewer`, from `add_role_to_users`).
 * This bootstrap owner is also deliberately exempt from the Users page's
 * `usersLimit()` check: it is created directly here, never through that
 * page.
 */
class InstallCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sendtrap:install
        {--name= : Display name for the first owner user}
        {--email= : Email address for the first owner user}
        {--password= : Password for the first owner user (prompted, hidden, if omitted)}
        {--workspace= : Display name for the single workspace (default: "Sendtrap")}
        {--force : Run non-interactively for CI/container bootstrap — fails instead of prompting for any missing owner field on a fresh install}';

    /**
     * @var string
     */
    protected $description = 'Idempotently install Sendtrap Community: run migrations, create the single workspace, and create the first owner user.';

    public function handle(): int
    {
        Artisan::call('migrate', ['--force' => true], $this->output);

        // Decided before installWorkspace() runs: only a run that creates
        // the workspace is a fresh install — the starter project must never
        // be provisioned onto a pre-existing instance.
        $freshInstall = Workspace::query()->doesntExist();

        $workspace = $this->installWorkspace();

        $this->installStarterProject($workspace, $freshInstall);

        return $this->installOwner();
    }

    /**
     * A fresh install gets one project with one inbox, so the first login
     * lands on visible SMTP/API credentials instead of an empty dashboard.
     *
     * Provisioned AT MOST ONCE per instance, remembered in settings: the
     * container reruns this command on every boot, so "no projects" cannot
     * mean "fresh install" — an operator who deleted their last project
     * must not have it resurrected on restart. Also subject to the same
     * entitlement checks as the Projects/Inboxes pages — a configured
     * limit of 0 blocks the starter too, and its inbox starts at the
     * configured per-inbox message cap.
     */
    private function installStarterProject(Workspace $workspace, bool $freshInstall): void
    {
        // Ephemeral CI instances keep a deterministic shape —
        // sendtrap:ci-seed owns their only project/inbox.
        if (env('SENDTRAP_MODE') === 'ci') {
            $this->components->twoColumnDetail('Starter project', 'skipped (SENDTRAP_MODE=ci)');

            return;
        }

        if (InstanceSettings::get('starter_project_provisioned')) {
            $this->components->twoColumnDetail('Starter project', 'already provisioned once — skipping');

            return;
        }

        // An instance that predates the marker is never fresh: whatever its
        // project count — zero included, when the operator deleted the last
        // one before upgrading — its state stands. Record the marker so this
        // stays settled.
        if (! $freshInstall || $workspace->projects()->exists()) {
            InstanceSettings::put('starter_project_provisioned', 1);
            $this->components->twoColumnDetail(
                'Starter project',
                $freshInstall ? 'a project already exists — skipping' : 'existing instance — skipping',
            );

            return;
        }

        $plan = app(Entitlements::class)->for($workspace);

        if (! $plan->within('projects', 0) || ! $plan->within('inboxes', 0)) {
            InstanceSettings::put('starter_project_provisioned', 1);
            $this->components->twoColumnDetail('Starter project', 'skipped (instance project/inbox limit is 0)');

            return;
        }

        $project = $workspace->projects()->create(['name' => 'My app']);

        $inboxAttributes = ['name' => 'Testing'];

        // Same starting cap InboxController applies on creation. A strict
        // null check: a configured 0 is a real (blocking) cap, not "use the
        // column default".
        if (($cap = $plan->messagesPerInbox()) !== null) {
            $inboxAttributes['max_messages'] = $cap;
        }

        $inbox = $project->inboxes()->create($inboxAttributes);

        InstanceSettings::put('starter_project_provisioned', 1);

        $this->components->info(
            "Project \"{$project->name}\" with inbox \"{$inbox->name}\" created — "
            .'its SMTP and API credentials are on the inbox page.'
        );
    }

    private function installWorkspace(): Workspace
    {
        $existing = Workspace::query()->first();

        if ($existing !== null) {
            $this->components->twoColumnDetail('Workspace', "already installed (#{$existing->id}) — skipping");

            // Re-persist the pointer in case it drifted (§3.3 self-heal) —
            // harmless no-op when it already points here.
            InstanceSettings::put('workspace_id', $existing->id);

            return $existing;
        }

        $workspace = Workspace::create([
            'name' => $this->option('workspace') ?: 'Sendtrap',
            'allowed_ips' => $this->parseAllowedIps((string) env('SENDTRAP_INSTANCE_ALLOWED_IPS', '')),
        ]);

        InstanceSettings::put('workspace_id', $workspace->id);

        $this->components->info("Workspace \"{$workspace->name}\" created.");

        return $workspace;
    }

    private function installOwner(): int
    {
        if (User::query()->exists()) {
            $this->components->twoColumnDetail('Owner user', 'a user already exists — skipping');

            return self::SUCCESS;
        }

        $credentials = $this->resolveOwnerCredentials();

        if ($credentials === null) {
            $this->components->error(
                '--force requires --name, --email, and --password together on a fresh '.
                'install — non-interactive mode does not prompt for missing fields.'
            );

            return self::FAILURE;
        }

        [$name, $email, $password] = $credentials;

        // `email_verified_at` is deliberately not in User::$fillable (mass
        // assignment would let a stray request field verify an email), so
        // it's set directly rather than through create()'s mass-assignment.
        // `role` is deliberately not in User::$fillable (mass-assignment
        // safety — see the User model docblock), so it's set directly here,
        // same as `email_verified_at`.
        $owner = new User([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        $owner->email_verified_at = now();
        $owner->role = Role::Owner;
        $owner->save();

        $this->components->info("Owner user \"{$email}\" created.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null null means a
     *                                                     non-interactive run is missing a required field.
     */
    private function resolveOwnerCredentials(): ?array
    {
        $name = $this->option('name');
        $email = $this->option('email');
        $password = $this->option('password');

        $nonInteractive = $this->option('force') || ($name && $email && $password);

        if ($nonInteractive) {
            if (! $name || ! $email || ! $password) {
                return null;
            }

            return [$name, $email, $password];
        }

        $name ??= text(label: 'Owner name', required: true);

        $email ??= text(
            label: 'Owner email',
            required: true,
            validate: fn (string $value) => filter_var($value, FILTER_VALIDATE_EMAIL)
                ? null
                : 'Enter a valid email address.',
        );

        $password ??= password(
            label: 'Owner password',
            required: true,
            validate: fn (string $value) => strlen($value) >= 8 ? null : 'Password must be at least 8 characters.',
        );

        return [$name, $email, $password];
    }

    private function parseAllowedIps(string $csv): ?array
    {
        $ips = array_values(array_filter(array_map('trim', explode(',', $csv))));

        return $ips === [] ? null : $ips;
    }
}
