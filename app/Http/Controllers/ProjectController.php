<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Http\Resources\InboxResource;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Support\IpAllowList;

/**
 * Community's own ProjectController — authored NEW, never lifted from the
 * Cloud host (Plan 06 Phase 4b design §7.2.1, F3). The Cloud controller is
 * Team-saturated (the user's current Team, `$team->projects()`, Team- and
 * plan-page route props); Community has none of those constructs. Every
 * workspace read resolves through the singleton
 * `WorkspaceContext::current()`, and the Team/upgrade props the package
 * components accept are passed as null so their links simply don't render
 * (the M-3 injected-prop pattern).
 */
class ProjectController extends Controller
{
    /**
     * Dashboard: the singleton workspace's projects and their inboxes.
     */
    public function index(Request $request): Response
    {
        $workspace = app(WorkspaceContext::class)->current();

        abort_unless($workspace !== null, 403);

        // InboxResource runs the §4.7 credential gate per inbox
        // (InboxPolicy::update reads $inbox->project->workspace), so the
        // relations are pinned in memory here — setRelation on both hops —
        // keeping the query count flat regardless of inbox count (the same
        // M-1 concern the Cloud host documents).
        $projects = $workspace->projects()
            ->with(['inboxes' => function ($query) {
                $query->withCount('messages')
                    ->withCount(['messages as unread_count' => fn ($q) => $q->where('is_read', false)])
                    ->orderBy('name');
            }])
            ->orderBy('name')
            ->get()
            ->map(function (Project $project) use ($workspace) {
                $project->setRelation('workspace', $workspace);
                $project->inboxes->each(fn ($inbox) => $inbox->setRelation('project', $project));

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'allowed_ips' => $project->allowed_ips ?? [],
                    'inboxes' => InboxResource::collection($project->inboxes)->resolve(),
                ];
            });

        return Inertia::render('Dashboard', [
            'projects' => $projects,
            'usage' => app(UsageMeter::class)->summary($workspace),
            // §7.2: no upgrade page exists — the package components'
            // upgrade links don't render when this is null (M-3).
            'upgradeUrl' => null,
        ]);
    }

    public function store(Request $request)
    {
        $workspace = app(WorkspaceContext::class)->current();

        abort_unless($workspace !== null, 403);

        // F2 (§4.8): the Cloud @store has no authorize() call — it leans on
        // Team-membership middleware Community doesn't have. Community
        // gates project creation explicitly, before validation/creation,
        // so a viewer is denied here with 403.
        Gate::authorize('manage-workspace', $workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        abort_unless(
            app(Entitlements::class)->for($workspace)->within('projects', $workspace->projects()->count()),
            403,
            'This instance’s project limit has been reached.',
        );

        $workspace->projects()->create($validated);

        return back();
    }

    public function update(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'allowed_ips' => ['nullable', 'array'],
            'allowed_ips.*' => [self::ipRule()],
        ]);

        $validated['allowed_ips'] = IpAllowList::normalize($request->input('allowed_ips', [])) ?: null;

        $project->update($validated);

        return back();
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorize('delete', $project);

        $project->delete();

        return back();
    }
}
