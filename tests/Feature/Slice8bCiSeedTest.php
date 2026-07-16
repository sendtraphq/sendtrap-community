<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Models\Workspace;
use Tests\CommunityTestCase;

/**
 * Plan 06 Phase 8b slice 1 (design §2.2, §2.3, §8.2) — the highest-value
 * static check: exercises the CI credential contract end-to-end in-process
 * (no Docker). Asserts sendtrap:ci-seed:
 *   - honours the SUPPLIED SENDTRAP_CI_* credentials (not random),
 *   - falls back to the documented fixed defaults when nothing is supplied,
 *   - is idempotent (a re-run makes no duplicate + re-emits the same contract),
 *   - emits the documented JSON contract shape to stdout,
 *   - writes the same JSON to the tmpfs file sink,
 *   - fails cleanly if the workspace is not yet installed.
 */
class Slice8bCiSeedTest extends CommunityTestCase
{
    /** @var array<int, string> env keys set during a test, cleared in tearDown */
    private array $envKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->envKeys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->envKeys = [];

        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        $this->envKeys[] = $key;
    }

    /** Install the singleton workspace + owner the way the CI entrypoint does. */
    private function installFresh(): void
    {
        Artisan::call('sendtrap:install', [
            '--force' => true,
            '--name' => 'CI',
            '--email' => 'ci@sendtrap.test',
            '--password' => 'ci-password-change-in-real-use',
            '--workspace' => 'Sendtrap',
        ]);
    }

    private function runSeed(): array
    {
        $code = Artisan::call('sendtrap:ci-seed');
        $output = Artisan::output();
        $line = trim(explode("\n", trim($output))[0]);

        return [$code, json_decode($line, true), $output];
    }

    public function test_seed_honours_supplied_ci_credentials(): void
    {
        $this->installFresh();

        $this->setEnv('SENDTRAP_CI_PROJECT', 'MyProj');
        $this->setEnv('SENDTRAP_CI_INBOX', 'MyInbox');
        $this->setEnv('SENDTRAP_CI_SMTP_USERNAME', 'ciuser');
        $this->setEnv('SENDTRAP_CI_SMTP_PASSWORD', 'super-secret-pass');
        $this->setEnv('SENDTRAP_CI_API_TOKEN', 'my-api-token-value');

        [$code] = $this->runSeed();

        $this->assertSame(0, $code);
        $this->assertSame(1, Project::query()->count());
        $this->assertSame(1, Inbox::query()->count());

        $project = Project::query()->first();
        $this->assertSame('MyProj', $project->name);

        $inbox = Inbox::query()->first();
        $this->assertSame('MyInbox', $inbox->name);
        $this->assertSame('ciuser', $inbox->smtp_username);
        // smtp_password decrypts (same APP_KEY) to exactly the supplied value —
        // NOT the random Str::random(24) default.
        $this->assertSame('super-secret-pass', $inbox->smtp_password);
        $this->assertSame('my-api-token-value', $inbox->api_token);
        // The inbox belongs to the seeded project of the installed workspace.
        $this->assertSame($project->id, $inbox->project_id);
    }

    public function test_seed_uses_documented_fixed_defaults_when_env_is_unset(): void
    {
        $this->installFresh();

        [$code, $contract] = $this->runSeed();

        $this->assertSame(0, $code);

        $inbox = Inbox::query()->first();
        $this->assertSame('ci', $inbox->smtp_username);
        $this->assertSame('ci-smtp-password', $inbox->smtp_password);
        $this->assertSame('ci-api-token', $inbox->api_token);
        $this->assertSame('ci', $inbox->name);
        $this->assertSame('CI', Project::query()->value('name'));

        // The contract mirrors the documented defaults.
        $this->assertSame('ci', $contract['smtp']['username']);
        $this->assertSame('ci-smtp-password', $contract['smtp']['password']);
        $this->assertSame('ci-api-token', $contract['api']['token']);
    }

    public function test_seed_is_idempotent_and_re_emits_the_same_contract(): void
    {
        $this->installFresh();
        $this->setEnv('SENDTRAP_CI_SMTP_USERNAME', 'ci');

        [$code1, $contract1] = $this->runSeed();
        $firstInboxId = Inbox::query()->value('id');
        $firstProjectId = Project::query()->value('id');

        [$code2, $contract2] = $this->runSeed();

        $this->assertSame(0, $code1);
        $this->assertSame(0, $code2);
        // No duplicate project or inbox on the second run.
        $this->assertSame(1, Inbox::query()->count());
        $this->assertSame(1, Project::query()->count());
        $this->assertSame($firstInboxId, Inbox::query()->value('id'));
        $this->assertSame($firstProjectId, Project::query()->value('id'));
        // Identical contract re-emitted.
        $this->assertSame($contract1, $contract2);
    }

    public function test_seed_emits_the_documented_contract_shape(): void
    {
        $this->installFresh();

        [$code, $contract] = $this->runSeed();

        $this->assertSame(0, $code);
        $this->assertIsArray($contract, 'ci-seed must print a single JSON contract line to stdout.');
        $this->assertSame('ci', $contract['mode']);

        // SMTP block.
        $this->assertArrayHasKey('smtp', $contract);
        $this->assertSame(1025, $contract['smtp']['port']);
        $this->assertArrayHasKey('username', $contract['smtp']);
        $this->assertArrayHasKey('password', $contract['smtp']);
        $this->assertIsBool($contract['smtp']['starttls']);
        $this->assertIsBool($contract['smtp']['require_tls']);

        // API block.
        $this->assertArrayHasKey('api', $contract);
        $this->assertStringEndsWith('/api/v1', $contract['api']['base_url']);
        $this->assertArrayHasKey('token', $contract['api']);
        $this->assertSame('POST /assert', $contract['api']['assert']);
        $this->assertSame('GET /messages?wait=N', $contract['api']['messages_wait']);
    }

    public function test_seed_writes_the_contract_file_sink(): void
    {
        $this->installFresh();

        $path = tempnam(sys_get_temp_dir(), 'ci-contract-').'.json';
        @unlink($path);
        $this->setEnv('SENDTRAP_CI_CONTRACT_PATH', $path);

        [$code, $contract] = $this->runSeed();

        $this->assertSame(0, $code);
        $this->assertFileExists($path);
        $onDisk = json_decode(trim((string) file_get_contents($path)), true);
        $this->assertSame($contract, $onDisk, 'The file sink must match the stdout contract.');

        @unlink($path);
    }

    public function test_seed_fails_cleanly_without_an_installed_workspace(): void
    {
        // No installFresh() — no workspace exists yet.
        $this->assertSame(0, Workspace::query()->count());

        $code = Artisan::call('sendtrap:ci-seed');

        $this->assertSame(1, $code);
        $this->assertSame(0, Inbox::query()->count());
        $this->assertSame(0, Project::query()->count());
    }
}
