<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\InstanceSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
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

        $workspace = $this->installWorkspace();

        $this->installStarterProject($workspace);

        return $this->installOwner();
    }

    /**
     * A fresh install gets one project with one inbox, so the first login
     * lands on visible SMTP/API credentials instead of an empty dashboard.
     * Skipped as soon as any project exists (including ci-seed's), so
     * re-runs and existing instances are untouched.
     */
    private function installStarterProject(Workspace $workspace): void
    {
        // Ephemeral CI instances keep a deterministic shape —
        // sendtrap:ci-seed owns their only project/inbox.
        if (env('SENDTRAP_MODE') === 'ci') {
            $this->components->twoColumnDetail('Starter project', 'skipped (SENDTRAP_MODE=ci)');

            return;
        }

        if ($workspace->projects()->exists()) {
            $this->components->twoColumnDetail('Starter project', 'a project already exists — skipping');

            return;
        }

        $project = $workspace->projects()->create(['name' => 'My app']);
        $inbox = $project->inboxes()->create(['name' => 'Testing']);

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
