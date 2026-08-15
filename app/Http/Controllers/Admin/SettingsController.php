<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SamparkArea;
use App\Models\Society;
use App\Services\AuditTrail;
use App\Services\KaryakarCategory;
use App\Services\OrganizationalScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __invoke(Request $request, OrganizationalScope $scope): Response
    {
        $user = $request->user();
        $centerIds = $scope->centers($user)->pluck('centers.id')->map(fn ($id) => (int) $id)->all();
        $canManageRoles = $user->hasPermission('manage_roles');

        return Inertia::render('admin/settings', [
            'roles' => $canManageRoles ? Role::query()->with('permissions:id,name,slug,module')->orderBy('name')->get() : [],
            'permissions' => $canManageRoles ? Permission::query()->orderBy('module')->orderBy('name')->get() : [],
            'canManageRoles' => $canManageRoles,
            'canDelegatePasswordReset' => $canManageRoles && $user->hasRole('super_admin'),
            'centers' => Center::query()->whereIn('id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'areas' => SamparkArea::query()->whereIn('center_id', $centerIds)->with('center:id,name,code')->orderBy('center_id')->orderBy('name')->get(),
            'societies' => Society::query()->whereIn('center_id', $centerIds)->with(['center:id,name,code', 'area:id,name'])->orderBy('center_id')->orderBy('name')->get(),
            'categories' => KaryakarCategory::CATEGORIES,
        ]);
    }

    public function updateRolePermissions(Request $request, Role $role, AuditTrail $audit): RedirectResponse
    {
        $data = $request->validate(['permission_ids' => ['array'], 'permission_ids.*' => ['integer', 'exists:permissions,id']]);
        $old = $role->permissions()->pluck('permissions.slug')->sort()->values()->all();
        $ids = collect($data['permission_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        // Password-reset delegation is intentionally Super-Admin-controlled even when
        // manage_roles is delegated for other permission maintenance. This prevents a
        // delegated role manager from granting itself account-takeover capability.
        $resetPermission = Permission::query()->where('slug', 'reset_user_passwords')->first();
        if ($resetPermission && ! $request->user()->hasRole('super_admin')) {
            $hadResetPermission = in_array('reset_user_passwords', $old, true);
            $requestsResetPermission = in_array((int) $resetPermission->id, $ids, true);
            abort_unless(
                $hadResetPermission === $requestsResetPermission,
                403,
                'Only Super Admin can grant or remove the Reset User Passwords permission.'
            );
        }

        $role->permissions()->sync($ids);
        $new = $role->permissions()->pluck('permissions.slug')->sort()->values()->all();
        $audit->record('roles', 'permissions_updated', Role::class, (string) $role->id, ['permissions' => $old], ['permissions' => $new]);
        return back()->with('success', "Permissions updated for {$role->name}.");
    }

    public function storeArea(Request $request): RedirectResponse
    {
        $request->merge([
            'external_code' => $request->filled('external_code') ? strtoupper(trim((string) $request->input('external_code'))) : null,
            'name' => trim((string) $request->input('name')),
        ]);
        $centerId = (int) $request->input('center_id');
        $data = $request->validate([
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'external_code' => ['nullable', 'string', 'max:100', Rule::unique('sampark_areas', 'external_code')->where(fn ($q) => $q->where('center_id', $centerId))],
            'name' => ['required', 'string', 'max:255', Rule::unique('sampark_areas', 'name')->where(fn ($q) => $q->where('center_id', $centerId))],
            'city_village' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        abort_unless($request->user()->canAccessCenterId((int) $data['center_id']), 403);
        SamparkArea::query()->create($data);
        return back()->with('success', 'Sampark Area master created.');
    }

    public function storeSociety(Request $request): RedirectResponse
    {
        $request->merge([
            'external_code' => $request->filled('external_code') ? strtoupper(trim((string) $request->input('external_code'))) : null,
            'name' => trim((string) $request->input('name')),
        ]);
        $centerId = (int) $request->input('center_id');
        $data = $request->validate([
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'sampark_area_id' => ['nullable', 'integer', 'exists:sampark_areas,id'],
            'external_code' => ['nullable', 'string', 'max:100', Rule::unique('societies', 'external_code')->where(fn ($q) => $q->where('center_id', $centerId))],
            'name' => ['required', 'string', 'max:255', Rule::unique('societies', 'name')->where(fn ($q) => $q->where('center_id', $centerId))],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        abort_unless($request->user()->canAccessCenterId((int) $data['center_id']), 403);
        if (! empty($data['sampark_area_id'])) {
            $area = SamparkArea::query()->findOrFail($data['sampark_area_id']);
            abort_unless((int) $area->center_id === (int) $data['center_id'], 422, 'Society Area must belong to the selected Center.');
        }
        Society::query()->create($data);
        return back()->with('success', 'Society master created.');
    }
}
