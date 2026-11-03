<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\RecordsAudit;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token', 'failed_login_attempts'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, RecordsAudit;

    /** Failed sign-ins allowed before the account is locked. */
    public const MAX_LOGIN_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 15;

    protected $appends = ['is_locked'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
        ];
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function getIsLockedAttribute(): bool
    {
        return $this->isLocked();
    }

    public function auditExcluded(): array
    {
        return ['password', 'remember_token', 'failed_login_attempts', 'locked_until'];
    }
}
