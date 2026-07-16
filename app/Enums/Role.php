<?php

namespace App\Enums;

/**
 * `users.role` values are `owner` | `member` | `viewer`. Backed by the DB
 * column's raw string so `User::role` casts to this enum for type-safe
 * comparisons instead of scattering string literals through policies/gates.
 *
 * Privilege separation between the three roles is deliberate and
 * load-bearing (credential visibility, user/settings management) — a
 * flattened everyone-can-do-everything model is explicitly rejected, and
 * the boundaries are pinned by the route-level role-matrix tests.
 */
enum Role: string
{
    case Owner = 'owner';
    case Member = 'member';
    case Viewer = 'viewer';

    /**
     * Roles that may manage email-domain resources (projects/inboxes/
     * messages) per `CommunityWorkspaceAccess::canManage()` (§4.2).
     *
     * @return array<int, self>
     */
    public static function managers(): array
    {
        return [self::Owner, self::Member];
    }

    /**
     * Roles that may view email-domain resources per
     * `CommunityWorkspaceAccess::canView()` (§4.2) — every role, viewer
     * included.
     *
     * @return array<int, self>
     */
    public static function viewers(): array
    {
        return [self::Owner, self::Member, self::Viewer];
    }
}
