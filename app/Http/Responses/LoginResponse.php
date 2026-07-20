<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Models\Inbox;

/**
 * Community's post-login landing. The installer's promise is that the first
 * login lands on visible SMTP/API credentials, but the dashboard only
 * surfaces the SMTP username — the full credential panel (SMTP password +
 * API token) lives on the inbox page. When the instance holds exactly one
 * inbox (the fresh-install starter shape), land there; otherwise — or when
 * a guest deep-linked somewhere (`intended`) — fall back to Fortify's
 * configured home.
 */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    public function toResponse($request)
    {
        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false])
            : redirect()->intended($this->defaultLanding());
    }

    private function defaultLanding(): string
    {
        $workspace = app(WorkspaceContext::class)->current();

        if ($workspace === null) {
            return config('fortify.home');
        }

        $inboxes = Inbox::query()
            ->whereRelation('project', 'workspace_id', $workspace->id)
            ->limit(2)
            ->get();

        return $inboxes->count() === 1
            ? route('inboxes.show', $inboxes->first())
            : config('fortify.home');
    }
}
