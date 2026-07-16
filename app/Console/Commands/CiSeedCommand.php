<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;

/**
 * Plan 06 Phase 8b (§2.2) — the ephemeral CI profile seed.
 *
 * Community-owned (principle 8 — NEVER core): after `sendtrap:install`
 * has created the singleton workspace + owner, this creates exactly one
 * project + one inbox with DETERMINISTIC, supplied credentials so a CI job
 * needs zero discovery, then emits the connection contract as a single JSON
 * line to stdout (the primary, --rm-surviving sink, read via `docker logs`)
 * and, best-effort, to /run/sendtrap/ci-contract.json (a secondary tmpfs
 * sink for a live container to `docker cp`).
 *
 * Credentials are INPUT: the SENDTRAP_CI_* env vars, each with a documented
 * fixed default (ci / ci-smtp-password / ci-api-token, project "CI", inbox
 * "ci"). They are honoured exactly because smtp_username/smtp_password/
 * api_token are all in Inbox::$fillable and Inbox::creating only fills them
 * when null (`??=`, vendor/sendtrap/core/src/Models/Inbox.php:97-101), so a
 * supplied value wins.
 *
 * Idempotent: the inbox is keyed on smtp_username via firstOrCreate, so a
 * re-run finds the existing inbox and re-emits the same contract without
 * creating a duplicate. Uses Workspace::first() — the proven singleton-
 * resolution path the durable e2e already relies on
 * (scripts/container-e2e.sh:105, design LOW-2).
 *
 * This command is invoked automatically only by the entrypoint's
 * SENDTRAP_MODE=ci branch; the durable-container E2E also invokes it
 * explicitly with isolated E2E credentials. It is never wired into normal
 * durable boot.
 */
class CiSeedCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sendtrap:ci-seed';

    /**
     * @var string
     */
    protected $description = 'Seed the ephemeral CI profile: one project + inbox with deterministic SENDTRAP_CI_* credentials, and emit the connection contract as JSON.';

    /** Default path for the secondary (file) contract sink; /run is tmpfs. */
    private const DEFAULT_CONTRACT_PATH = '/run/sendtrap/ci-contract.json';

    public function handle(): int
    {
        $projectName = (string) env('SENDTRAP_CI_PROJECT', 'CI');
        $inboxName = (string) env('SENDTRAP_CI_INBOX', 'ci');
        $smtpUser = (string) env('SENDTRAP_CI_SMTP_USERNAME', 'ci');
        $smtpPass = (string) env('SENDTRAP_CI_SMTP_PASSWORD', 'ci-smtp-password');
        $apiToken = (string) env('SENDTRAP_CI_API_TOKEN', 'ci-api-token');

        $workspace = Workspace::first();

        if ($workspace === null) {
            $this->components->error(
                'sendtrap:ci-seed found no workspace — run sendtrap:install first '.
                '(the CI entrypoint does this before ci-seed).'
            );

            return self::FAILURE;
        }

        // Project through the relation (workspace_id is NOT $fillable — set via
        // the relation FK, exactly as ProjectController + the durable e2e do).
        $project = $workspace->projects()->firstOrCreate(['name' => $projectName]);

        // Inbox keyed on smtp_username for idempotency; supplied creds win over
        // the ??= random defaults in Inbox::creating. smtp_password is
        // Crypt-encrypted on write (same APP_KEY as this boot, so SMTP AUTH
        // decrypts + matches); api_token is a plaintext column.
        $inbox = Inbox::firstOrCreate(
            ['smtp_username' => $smtpUser],
            [
                'project_id' => $project->id,
                'name' => $inboxName,
                'smtp_password' => $smtpPass,
                'api_token' => $apiToken,
            ],
        );

        $contract = $this->contract($smtpUser, $smtpPass, $apiToken);
        $json = json_encode($contract, JSON_UNESCAPED_SLASHES);

        // Primary sink: a single JSON line to stdout (survives --rm via
        // `docker logs`). $this->line writes to stdout without decoration.
        $this->line($json);

        // Secondary sink: best-effort write to the tmpfs file. A failure here
        // (e.g. /run not writable) MUST NOT fail the seed — stdout is primary.
        $this->writeContractFile($json);

        // Silence "unused" static analysers: the inbox exists / was created.
        unset($inbox);

        return self::SUCCESS;
    }

    /**
     * The credential + connection contract (design §2.3). host/base_url reflect
     * the container-internal bind and are advisory/debug — the authoritative
     * discovery is the documented SENDTRAP_CI_* env contract.
     *
     * @return array<string, mixed>
     */
    private function contract(string $smtpUser, string $smtpPass, string $apiToken): array
    {
        $port = (int) config('sendtrap.smtp_port', 1025);
        $baseUrl = rtrim((string) config('app.url', 'http://localhost:8080'), '/').'/api/v1';

        return [
            'mode' => 'ci',
            'smtp' => [
                'host' => '0.0.0.0',
                'port' => $port,
                'username' => $smtpUser,
                'password' => $smtpPass,
                'starttls' => (bool) config('sendtrap.tls', true),
                'require_tls' => (bool) config('sendtrap.require_tls', false),
            ],
            'api' => [
                'base_url' => $baseUrl,
                'token' => $apiToken,
                'assert' => 'POST /assert',
                'messages_wait' => 'GET /messages?wait=N',
            ],
        ];
    }

    /**
     * Best-effort write of the JSON contract to the tmpfs file sink. Overridable
     * via SENDTRAP_CI_CONTRACT_PATH (defaults to /run/sendtrap/ci-contract.json).
     * Never throws / fails the command — stdout is the primary contract sink.
     */
    private function writeContractFile(string $json): void
    {
        $path = (string) env('SENDTRAP_CI_CONTRACT_PATH', self::DEFAULT_CONTRACT_PATH);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                $this->warn("ci-seed: could not create {$dir}; contract is on stdout only.");

                return;
            }
        }

        if (@file_put_contents($path, $json.PHP_EOL) === false) {
            $this->warn("ci-seed: could not write {$path}; contract is on stdout only.");
        }
    }
}
