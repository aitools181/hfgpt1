<?php

namespace App\Services\Support;

use App\Models\User;
use App\Services\OrganizationalScope;
use Illuminate\Database\Eloquent\Builder;

class SupportScopeService
{
    public function __construct(private readonly OrganizationalScope $scope) {}

    public function visibleCenterIds(User $user): array
    {
        return $this->scope->centers($user)->pluck('centers.id')->map(fn ($id) => (int) $id)->all();
    }

    public function applyGlobalOrCenterScope(Builder $query, User $user, string $column = 'center_id'): Builder
    {
        if ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin')) {
            return $query;
        }

        $centerIds = $this->visibleCenterIds($user);
        return $query->where(function (Builder $q) use ($column, $centerIds): void {
            $q->whereNull($column);
            if ($centerIds !== []) {
                $q->orWhereIn($column, $centerIds);
            }
        });
    }

    public function primaryCenterId(User $user): ?int
    {
        if ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin')) {
            return null;
        }

        $role = $user->primaryRole();
        $centerId = $role?->pivot?->center_id;
        return $centerId ? (int) $centerId : null;
    }

    public function assertCenterAccess(User $user, ?int $centerId): void
    {
        if ($centerId !== null && ! $user->canAccessCenterId($centerId)) {
            abort(403, 'Center is outside your permitted scope.');
        }
    }
}
