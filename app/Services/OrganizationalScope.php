<?php

namespace App\Services;

use App\Models\Center;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;

class OrganizationalScope
{
    public function zones(User $user): Builder
    {
        $query = Zone::query();
        if ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin')) {
            return $query;
        }

        // Only the Zonal Admin role grants Zone-wide scope. Center-level roles also carry
        // zone_id for context/audit traceability, but that must never broaden their scope.
        $ids = $user->roles->filter(fn ($role) => $role->slug === 'zonal_admin')
            ->pluck('pivot.zone_id')->filter()->unique()->values();
        return $ids->isEmpty() ? $query->whereRaw('1 = 0') : $query->whereIn('id', $ids);
    }

    public function centers(User $user): Builder
    {
        $query = Center::query()->with('zone');
        if ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin')) {
            return $query;
        }

        $centerIds = $user->roles->pluck('pivot.center_id')->filter()->unique()->values();
        $zoneIds = $user->roles->filter(fn ($role) => $role->slug === 'zonal_admin')
            ->pluck('pivot.zone_id')->filter()->unique()->values();

        if ($centerIds->isEmpty() && $zoneIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($centerIds, $zoneIds): void {
            if ($centerIds->isNotEmpty()) {
                $q->whereIn('id', $centerIds);
            }
            if ($zoneIds->isNotEmpty()) {
                $method = $centerIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                $q->{$method}('zone_id', $zoneIds);
            }
        });
    }
}
