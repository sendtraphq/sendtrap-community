<?php

use App\Http\Controllers\InboxController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\QuickstartController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Sendtrap\Core\Http\Controllers\MessageController;

/*
 * Roadmap quick win #9: an installed instance goes straight to login; a
 * from-source checkout mid-install gets a live quickstart checklist
 * instead of the stock framework welcome page.
 */
Route::get('/', QuickstartController::class)->name('home');

/*
 * Public docs stubs (Plan 06 Phase 4b design §8 row 31, §11 slice 7):
 * Community's OWN terms/policy pages — never Cloud's marketing terms.
 * Content is Phase 6 publication work; these are the design-sanctioned
 * Phase 4b stub routes. Cloud route names (terms.show / policy.show) are
 * kept so any shared component linking them keeps resolving.
 */
Route::get('/terms', fn () => Inertia::render('Legal/Terms'))->name('terms.show');
Route::get('/policy', fn () => Inertia::render('Legal/Policy'))->name('policy.show');

/*
 * API contract + interactive reference — parity with Cloud's /docs/api/*
 * URLs. The OpenAPI document ships inside the sendtrap/core package (the
 * single source of truth for both editions); these routes only expose it.
 * Every route 404s gracefully on a core version that predates the
 * openapi/ directory (< 0.2), so wiring can ship ahead of the bump.
 */
Route::get('/docs/api/reference', function () {
    abort_unless(file_exists(base_path('vendor/sendtrap/core/openapi/sendtrap.yaml')), 404);

    return view('api-reference');
})->name('docs.api.reference');
Route::get('/docs/api/openapi.{format}', function (string $format) {
    abort_unless(in_array($format, ['yaml', 'json']), 404);
    $path = base_path('vendor/sendtrap/core/openapi/sendtrap.'.$format);
    abort_unless(file_exists($path), 404);

    return response()->file($path, [
        'Content-Type' => $format === 'yaml' ? 'application/yaml; charset=utf-8' : 'application/json',
    ]);
})->name('docs.api.contract');
Route::get('/docs/api/sendtrap.postman_collection.json', function () {
    $path = base_path('vendor/sendtrap/core/openapi/sendtrap.postman_collection.json');
    abort_unless(file_exists($path), 404);

    return response()->file($path, [
        'Content-Type' => 'application/json',
        'Content-Disposition' => 'attachment; filename="sendtrap.postman_collection.json"',
    ]);
})->name('docs.api.postman');

/*
 * The H-5 domain route group (Plan 06 Phase 4b design §4.8) — Community
 * authors this itself; the package's own routes/web.php carries only the
 * public share.* routes. Verbs/URIs mirror the Cloud host's exactly.
 *
 * Gate carriers, per the §4.8 table:
 *  - message read surfaces + `messages.read` → package `authorize('view')`
 *    (a viewer may read and mark a single message read — §4.4);
 *  - project/inbox mutations → Community controllers' policy authorize()
 *    calls (canManage), with `projects.store` explicitly behind
 *    `Gate::authorize('manage-workspace')` in the controller (F2);
 *  - `messages.share` / `inboxes.share` / `inboxes.share.destroy` →
 *    route-level `can:manage-workspace` (§4.5: the package gates share on
 *    `view`, which would let a viewer mint a durable public link — the
 *    host-side route gate closes that escalation).
 *
 * Plain `auth` only — no `verified` wall (Community provisions users via
 * the installer/owner, not self-signup; §4.8 lists `auth` as the group).
 */
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [ProjectController::class, 'index'])->name('dashboard');

    // API docs (§8 row 28, Adapted): Community-authored page documenting
    // the core inbox API, minus Cloud-plan notes. Auth-only — it is part
    // of the app shell (AppLayout nav gates on hasRoute('docs.api')),
    // not a public marketing surface (row 27 is Cloud-only).
    Route::get('/docs/api', fn () => Inertia::render('Docs/Api'))->name('docs.api');

    // Profile (§7.1 nav → Profile; §7.3 — Fortify-backed, Community-authored page)
    Route::get('/user/profile', function () {
        return Inertia::render('Profile/Show', [
            'confirmsTwoFactorAuthentication' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
        ]);
    })->name('profile.show');

    // Projects
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    // Inboxes
    Route::post('/projects/{project}/inboxes', [InboxController::class, 'store'])->name('inboxes.store');
    Route::get('/inboxes/{inbox}', [InboxController::class, 'show'])->name('inboxes.show');
    Route::get('/inboxes/{inbox}/settings', [InboxController::class, 'settings'])->name('inboxes.settings');
    Route::put('/inboxes/{inbox}', [InboxController::class, 'update'])->name('inboxes.update');
    Route::post('/inboxes/{inbox}/read-all', [InboxController::class, 'markAllRead'])->name('inboxes.read-all');
    Route::post('/inboxes/{inbox}/clear', [InboxController::class, 'clear'])->name('inboxes.clear');
    Route::delete('/inboxes/{inbox}', [InboxController::class, 'destroy'])->name('inboxes.destroy');
    Route::post('/inboxes/{inbox}/share', [InboxController::class, 'share'])
        ->middleware('can:manage-workspace')
        ->name('inboxes.share');
    Route::delete('/inboxes/{inbox}/share', [InboxController::class, 'revokeShare'])
        ->middleware('can:manage-workspace')
        ->name('inboxes.share.destroy');

    // Messages — the package web MessageController (§4.8)
    Route::get('/inboxes/{inbox}/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{message}', [MessageController::class, 'show'])->name('messages.show');
    Route::get('/messages/{message}/raw', [MessageController::class, 'raw'])->name('messages.raw');
    Route::get('/messages/{message}/spam', [MessageController::class, 'spam'])->name('messages.spam');
    Route::get('/messages/{message}/html-check', [MessageController::class, 'htmlCheck'])->name('messages.htmlcheck');
    Route::get('/messages/{message}/html', [MessageController::class, 'html'])->name('messages.html');
    Route::get('/messages/{message}/attachments/{attachment}', [MessageController::class, 'attachment'])
        ->name('messages.attachment');
    Route::patch('/messages/{message}/read', [MessageController::class, 'markRead'])->name('messages.read');
    Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');
    Route::post('/messages/{message}/share', [MessageController::class, 'share'])
        ->middleware('can:manage-workspace')
        ->name('messages.share');

    // Users — owner-only via UserPolicy, the §4.8 gate carrier for
    // `users.*` (§4.6; every action authorize()s through the policy).
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    // Settings — owner-only via the route-level `can:manage-instance`
    // gate, the §4.8 carrier for `settings*` (§4.6).
    Route::get('/settings', [SettingsController::class, 'show'])
        ->middleware('can:manage-instance')
        ->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])
        ->middleware('can:manage-instance')
        ->name('settings.update');
});
