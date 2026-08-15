<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UserAdministrationScope
{
    private const ROLE_RANK = [
        'super_admin' => 100,
        'bn_karyalay_admin' => 90,
        'zonal_admin' => 80,
        'center_admin' => 70,
        'computer_op' => 60,
        'nirdeshak' => 50,
        'nirikshak' => 40,
        'sanchalak' => 30,
        'karyakar' => 20,
    ];

    public function visibleUsers(User $actor): Builder
    {
        $query = User::query();
        if ($this->hasOrganizationWideScope($actor)) {
            return $query;
        }

        $centerIds = $this->centerIds($actor);
        $zoneIds = $this->zoneIds($actor);

        if ($centerIds->isEmpty() && $zoneIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('roles', function (Builder $roleQuery) use ($centerIds, $zoneIds): void {
            $roleQuery->where(function (Builder $scopeQuery) use ($centerIds, $zoneIds): void {
                if ($centerIds->isNotEmpty()) {
                    $scopeQuery->whereIn('user_roles.center_id', $centerIds);
                }
                if ($zoneIds->isNotEmpty()) {
                    $method = $centerIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $scopeQuery->{$method}('user_roles.zone_id', $zoneIds);
                }
            });
        });
    }

    public function canResetPassword(User $actor, User $target): bool
    {
        if (! $actor->hasPermission('reset_user_passwords')) {
            return false;
        }

        if ($actor->hasRole('super_admin')) {
            return true;
        }

        if ($target->hasRole('super_admin')) {
            return false;
        }

        if (! $this->targetIsInScope($actor, $target)) {
            return false;
        }

        return $this->highestRank($target) <= $this->highestRank($actor);
    }

    public function assertCanResetPassword(User $actor, User $target): void
    {
        abort_unless($this->canResetPassword($actor, $target), 403, 'This user is outside your password-reset authority.');
    }

    public function canManageTarget(User $actor, User $target): bool
    {
        if (! $actor->hasPermission('manage_users')) {
            return false;
        }

        if ($actor->hasRole('super_admin')) {
            return true;
        }

        if ($target->hasRole('super_admin')) {
            return false;
        }

        return $this->targetIsInScope($actor, $target)
            && $this->highestRank($target) <= $this->highestRank($actor);
    }

    public function assertCanManageTarget(User $actor, User $target): void
    {
        abort_unless($this->canManageTarget($actor, $target), 403, 'This user is outside your user-management authority.');
    }

    public function assignableRoles(User $actor): Builder
    {
        if ($actor->hasRole('super_admin')) {
            return Role::query();
        }

        $rank = $this->highestRank($actor);
        $slugs = collect(self::ROLE_RANK)
            ->filter(fn (int $roleRank, string $slug): bool => $roleRank <= $rank && $slug !== 'super_admin')
            ->keys();

        if (! $this->hasOrganizationWideScope($actor)) {
            $slugs = $slugs->reject(fn (string $slug): bool => in_array($slug, ['bn_karyalay_admin'], true));
        }

        if ($this->zoneIds($actor)->isEmpty()) {
            $slugs = $slugs->reject(fn (string $slug): bool => $slug === 'zonal_admin');
        }

        return Role::query()->whereIn('slug', $slugs->values()->all());
    }

    public function assertCanAssign(User $actor, Role $role, ?int $zoneId, ?int $centerId): void
    {
        abort_unless($actor->hasPermission('manage_users'), 403, 'You do not have permission to manage users.');
        abort_unless($this->assignableRoles($actor)->whereKey($role->id)->exists(), 403, 'You cannot assign this role.');

        if (in_array($role->slug, ['super_admin', 'bn_karyalay_admin'], true)) {
            abort_unless($this->hasOrganizationWideScope($actor), 403, 'Organization-wide roles require organization-wide authority.');
            return;
        }

        if ($role->slug === 'zonal_admin') {
            abort_unless($zoneId && $actor->canAccessZoneId($zoneId), 403, 'The selected Zone is outside your authority.');
            return;
        }

        abort_unless($centerId && $actor->canAccessCenterId($centerId), 403, 'The selected Center is outside your authority.');
    }

    private function hasOrganizationWideScope(User $user): bool
    {
        return $user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin');
    }

    private function targetIsInScope(User $actor, User $target): bool
    {
        if ($this->hasOrganizationWideScope($actor)) {
            return true;
        }

        $centerIds = $this->centerIds($actor);
        $zoneIds = $this->zoneIds($actor);

        if ($target->roles->isEmpty()) {
            return false;
        }

        // Password/user administration affects the whole login identity. A delegated
        // administrator may act only when every assigned role is inside their scope.
        return $target->roles->every(function (Role $role) use ($centerIds, $zoneIds): bool {
            $centerId = $role->pivot->center_id;
            $zoneId = $role->pivot->zone_id;

            return ($centerId !== null && $centerIds->contains((int) $centerId))
                || ($zoneId !== null && $zoneIds->contains((int) $zoneId));
        });
    }

    private function centerIds(User $user): Collection
    {
        return $user->roles->pluck('pivot.center_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
    }

    private function zoneIds(User $user): Collection
    {
        return $user->roles
            ->filter(fn (Role $role): bool => $role->slug === 'zonal_admin')
            ->pluck('pivot.zone_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
    }

    private function highestRank(User $user): int
    {
        return (int) ($user->roles->max(fn (Role $role): int => self::ROLE_RANK[$role->slug] ?? 0) ?? 0);
    }
}
