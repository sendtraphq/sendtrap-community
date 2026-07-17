<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Sendtrap\Core\Models\Workspace;
use Throwable;

/**
 * The root route. An installed instance redirects straight to login (the
 * container's boot oneshot always installs, so container users never see
 * anything else). A from-source checkout that hasn't finished the README
 * yet instead gets a quickstart page: the remaining steps, each with a
 * live check where the app can detect it. The page renders plain Blade
 * with inline styles on purpose — one of the things it reports is that the
 * Vite build hasn't been run yet.
 */
class QuickstartController extends Controller
{
    public function __invoke(): RedirectResponse|View
    {
        if ($this->installed()) {
            return redirect()->route('login');
        }

        return view('quickstart', [
            'steps' => $this->steps(),
            'smtpPort' => (int) config('sendtrap.smtp_port'),
        ]);
    }

    /**
     * Installed = the singleton workspace and the first owner both exist.
     * Any DB-level failure (no connection, migrations pending) reads as
     * not-installed — exactly the audience this page serves.
     */
    private function installed(): bool
    {
        try {
            return Schema::hasTable('workspaces')
                && Workspace::query()->exists()
                && User::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{command: string, detail: string, done: ?bool}>
     *         `done` null = not detectable from here, shown neutrally.
     */
    private function steps(): array
    {
        $migrated = false;
        $installed = false;

        try {
            $migrated = Schema::hasTable('workspaces');
            $installed = $migrated && Workspace::query()->exists() && User::query()->exists();
        } catch (Throwable) {
            // No usable database yet — both stay false.
        }

        return [
            [
                'command' => 'php artisan sendtrap:install',
                'detail' => $migrated
                    ? 'Creates the workspace, your owner login, and a starter inbox.'
                    : 'Runs the migrations, then creates the workspace, your owner login, and a starter inbox.',
                'done' => $installed,
            ],
            [
                'command' => 'npm install && npm run build',
                'detail' => 'Builds the front-end once — nothing Node runs afterwards.',
                'done' => is_file(public_path('build/manifest.json')),
            ],
            [
                'command' => 'php artisan mail:smtp-server',
                'detail' => 'The SMTP ingestion daemon your app will send mail to.',
                'done' => $this->smtpAnswering(),
            ],
            [
                'command' => 'php artisan sendtrap:send-test',
                'detail' => 'Seeds a rich example message — then log in and watch it appear.',
                'done' => null,
            ],
        ];
    }

    private function smtpAnswering(): bool
    {
        $socket = @fsockopen('127.0.0.1', (int) config('sendtrap.smtp_port'), $errno, $error, 0.3);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
