<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * `role` is deliberately absent from `$fillable` (Plan 06 Phase 4b design
 * §4.1): mass assignment must never let a stray request field self-escalate
 * a role. Every role write goes through an explicit attribute assignment —
 * `sendtrap:install` (the first owner) and the future owner-only Users page
 * (§4.6) — never through `create()`/`fill()`.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === Role::Owner;
    }

    public function isMember(): bool
    {
        return $this->role === Role::Member;
    }

    public function isViewer(): bool
    {
        return $this->role === Role::Viewer;
    }

    /**
     * Whether this user may manage email-domain resources (§4.2) —
     * owner or member.
     */
    public function canManageWorkspace(): bool
    {
        return in_array($this->role, Role::managers(), true);
    }
}
