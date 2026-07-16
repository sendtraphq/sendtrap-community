<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Plan 06 Phase 4b design §7.1: the Community AppLayout gates its
     * owner-only nav items on `$page.props.auth.user.role === 'owner'`,
     * shared here. `flash.share_url` is what the package MessageReader
     * reads after `messages.share` flashes the created link
     * (MessageReader.vue: `page.props.flash?.share_url`).
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->value,
                    'email_verified_at' => $user->email_verified_at,
                    'two_factor_enabled' => $user->two_factor_secret !== null
                        && $user->two_factor_confirmed_at !== null,
                ],
            ],
            'flash' => [
                'share_url' => fn () => $request->session()->get('share_url'),
            ],
        ];
    }
}
