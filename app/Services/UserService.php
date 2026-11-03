<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\DomainRuleException;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserService
{
    public function __construct(private AuditLogger $audit) {}

    /** @return Collection<int, User> */
    public function list(): Collection
    {
        return User::query()->orderBy('name')->get();
    }

    public function create(array $data): User
    {
        return User::create($data)->refresh();
    }

    /**
     * Updates profile, role or active flag. The last active admin can't be demoted or
     * deactivated, and nobody can deactivate their own account.
     */
    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $losesAdmin = $user->hasRole(Role::Admin) && $user->is_active && (
                (isset($data['role']) && $data['role'] !== Role::Admin->value)
                || (array_key_exists('is_active', $data) && ! $data['is_active'])
            );

            if (array_key_exists('is_active', $data) && ! $data['is_active'] && $user->is($actor)) {
                throw new DomainRuleException('You cannot deactivate your own account.', [
                    'is_active' => ['Ask another admin to deactivate this account.'],
                ]);
            }

            if ($losesAdmin && $this->activeAdminCount($user) === 0) {
                throw new DomainRuleException('There must be at least one active admin.', [
                    'role' => ['This is the last active admin. Make another user an admin first.'],
                ]);
            }

            $wasActive = $user->is_active;
            $user->update($data);

            // Deactivation signs the user out everywhere.
            if ($wasActive && ! $user->is_active) {
                $user->tokens()->delete();
            }

            return $user->refresh();
        });
    }

    public function unlock(User $user): User
    {
        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->saveQuietly();
        $this->audit->record('unlocked', $user);

        return $user->refresh();
    }

    /** Active admins other than $except, locked for update so two demotions can't race. */
    private function activeAdminCount(User $except): int
    {
        return User::query()
            ->where('role', Role::Admin->value)
            ->where('is_active', true)
            ->whereKeyNot($except->getKey())
            ->lockForUpdate()
            ->pluck('id') // PostgreSQL can't lock an aggregate, so lock the rows and count here.
            ->count();
    }
}
