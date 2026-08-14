<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Karyakar;
use App\Models\Role;
use App\Models\User;
use App\Models\Zone;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users', [
            'users' => User::query()->with('roles')->orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'roles' => $user->roles->map(fn ($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'zone_id' => $role->pivot->zone_id,
                    'center_id' => $role->pivot->center_id,
                    'is_primary' => (bool) $role->pivot->is_primary,
                ])->values(),
            ]),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'slug', 'module']),
            'zones' => Zone::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'centers' => Center::query()->where('status', 'active')->orderBy('name')->get(['id', 'zone_id', 'name', 'code']),
            'karyakars' => Karyakar::query()->where('status', 'approved')->orderBy('full_name')->get(['id', 'center_id', 'user_id', 'full_name', 'karyakar_reference']),
        ]);
    }

    public function store(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $auditTrail): void {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'status' => $data['status'],
                'email_verified_at' => now(),
            ]);
            $this->syncPrimaryRole($user, $data);
            $this->syncKaryakarLink($user, $data);
            $auditTrail->record('users', 'created', User::class, (string) $user->id, [], ['name' => $user->name, 'email' => $user->email, 'role_id' => $data['role_id'], 'zone_id' => $data['zone_id'] ?? null, 'center_id' => $data['center_id'] ?? null, 'karyakar_id' => $data['karyakar_id'] ?? null]);
        });
        return back()->with('success', 'User created successfully.');
    }

    public function update(Request $request, User $user, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $this->validated($request, $user);
        DB::transaction(function () use ($data, $user, $auditTrail): void {
            $old = ['name' => $user->name, 'email' => $user->email, 'status' => $user->status];
            $payload = ['name' => $data['name'], 'email' => $data['email'], 'status' => $data['status']];
            if (! empty($data['password'])) {
                $payload['password'] = Hash::make($data['password']);
            }
            $user->update($payload);
            $this->syncPrimaryRole($user, $data);
            $this->syncKaryakarLink($user, $data);
            $auditTrail->record('users', 'updated', User::class, (string) $user->id, $old, ['name' => $user->name, 'email' => $user->email, 'status' => $user->status, 'role_id' => $data['role_id'], 'zone_id' => $data['zone_id'] ?? null, 'center_id' => $data['center_id'] ?? null, 'karyakar_id' => $data['karyakar_id'] ?? null]);
        });
        return back()->with('success', 'User updated successfully.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'role_id' => ['required', 'exists:roles,id'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'center_id' => ['nullable', 'exists:centers,id'],
            'karyakar_id' => ['nullable', 'integer', 'exists:karyakars,id'],
        ]);
    }

    private function syncPrimaryRole(User $user, array $data): void
    {
        $role = Role::query()->findOrFail($data['role_id']);
        $zoneId = $data['zone_id'] ?? null;
        $centerId = $data['center_id'] ?? null;

        if (in_array($role->slug, ['super_admin', 'bn_karyalay_admin'], true)) {
            $zoneId = null;
            $centerId = null;
        } elseif ($role->slug === 'zonal_admin') {
            abort_unless($zoneId, 422, 'Zone is required for Zonal Admin.');
            $centerId = null;
        } else {
            abort_unless($centerId, 422, 'Center is required for this role.');
            $center = Center::query()->findOrFail($centerId);
            $zoneId = $center->zone_id;
        }

        $user->roles()->detach();
        $user->roles()->attach($role->id, ['zone_id' => $zoneId, 'center_id' => $centerId, 'is_primary' => true]);
    }

    private function syncKaryakarLink(User $user, array $data): void
    {
        $role = Role::query()->findOrFail($data['role_id']);
        $requiresKaryakarLink = in_array($role->slug, ['karyakar', 'sanchalak'], true);
        if (! $requiresKaryakarLink) {
            Karyakar::query()->where('user_id', $user->id)->get()->each(fn (Karyakar $linked) => $linked->update(['user_id' => null]));
            return;
        }

        Karyakar::query()->where('user_id', $user->id)->where('id', '!=', (int) ($data['karyakar_id'] ?? 0))->get()->each(fn (Karyakar $linked) => $linked->update(['user_id' => null]));

        if ($role->slug === 'sanchalak') {
            abort_unless(! empty($data['karyakar_id']), 422, 'Sanchalak must be linked to an Approved Sankalp Karyakar.');
        } elseif (empty($data['karyakar_id'])) {
            return;
        }

        $karyakar = Karyakar::query()->findOrFail($data['karyakar_id']);
        abort_unless((int) $karyakar->center_id === (int) ($data['center_id'] ?? 0), 422, 'Linked Karyakar must belong to the selected Center.');
        abort_if($karyakar->status !== 'approved', 422, 'Only an Approved Karyakar can be linked to a portal Karyakar user.');
        abort_if($karyakar->user_id && $karyakar->user_id !== $user->id, 422, 'This Karyakar is already linked to another portal user.');
        $karyakar->update(['user_id' => $user->id]);
    }
}
