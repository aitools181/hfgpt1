<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'status', 'email_verified_at', 'last_login_at'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'last_login_at' => 'datetime', 'password' => 'hashed'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['zone_id', 'center_id', 'is_primary'])
            ->withTimestamps();
    }

    public function primaryRole(): ?Role
    {
        return $this->roles->first(fn (Role $role) => (bool) $role->pivot->is_primary) ?? $this->roles->first();
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains('slug', $slug);
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        return $this->roles->contains(function (Role $role) use ($permission): bool {
            return $role->permissions->contains('slug', $permission);
        });
    }

    public function canAccessZoneId(?int $zoneId): bool
    {
        if ($zoneId === null || $this->hasRole('super_admin') || $this->hasRole('bn_karyalay_admin')) {
            return true;
        }

        // A stored zone_id on a Center-level role is contextual metadata, not Zone-wide authority.
        return $this->roles->contains(fn (Role $role) => $role->slug === 'zonal_admin'
            && (int) ($role->pivot->zone_id ?? 0) === $zoneId);
    }

    public function canAccessCenterId(?int $centerId): bool
    {
        if ($centerId === null || $this->hasRole('super_admin') || $this->hasRole('bn_karyalay_admin')) {
            return true;
        }

        if ($this->roles->contains(fn (Role $role) => (int) ($role->pivot->center_id ?? 0) === $centerId)) {
            return true;
        }

        $zoneId = Center::query()->whereKey($centerId)->value('zone_id');
        return $zoneId !== null && $this->roles->contains(fn (Role $role) => $role->slug === 'zonal_admin'
            && (int) ($role->pivot->zone_id ?? 0) === (int) $zoneId);
    }
}
