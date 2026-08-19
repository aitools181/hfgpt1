<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Karyakar;
use App\Models\Role;
use App\Models\User;
use App\Models\Zone;
use App\Services\AuditTrail;
use App\Services\OrganizationalScope;
use App\Services\UserAdministrationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request, OrganizationalScope $scope, UserAdministrationScope $userScope): Response
    {
        $actor = $request->user();
        $canManageUsers = $actor->hasPermission('manage_users');
        $canResetPasswords = $actor->hasPermission('reset_user_passwords');
        abort_unless($canManageUsers || $canResetPasswords, 403);

        $userSearch = trim((string) $request->query('search', ''));
        $candidateQuery = $userScope->visibleUsers($actor)->with('roles');
        if ($userSearch !== '') {
            $candidateQuery->where(function ($query) use ($userSearch): void {
                $query->where('name', 'ilike', '%'.$userSearch.'%')
                    ->orWhere('email', 'ilike', '%'.$userSearch.'%');
            });
        }

        // The page is intentionally bounded. A global Super Admin installation can
        // contain thousands of users; search finds any user without serializing the
        // whole user table into one Inertia response.
        $candidates = $candidateQuery->orderBy('name')->limit(501)->get();
        $candidateLimitReached = $candidates->count() === 501;
        $authorizedUsers = $candidates->filter(fn (User $user): bool => ($canManageUsers && $userScope->canManageTarget($actor, $user))
                || ($canResetPasswords && $userScope->canResetPassword($actor, $user)))
            ->values();
        $userListTruncated = $candidateLimitReached || $authorizedUsers->count() > 250;
        $visibleUsers = $authorizedUsers->take(250);
        $visibleZoneIds = $visibleUsers->flatMap(fn (User $user) => $user->roles->pluck('pivot.zone_id'))->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $visibleCenterIds = $visibleUsers->flatMap(fn (User $user) => $user->roles->pluck('pivot.center_id'))->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $zoneLabels = Zone::query()->whereIn('id', $visibleZoneIds)->get(['id', 'name', 'code'])->keyBy('id');
        $centerLabels = Center::query()->whereIn('id', $visibleCenterIds)->get(['id', 'name', 'code'])->keyBy('id');

        $users = $visibleUsers->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'last_login_at' => $user->last_login_at,
                'password_changed_at' => $user->password_changed_at,
                'can_reset_password' => $canResetPasswords && $userScope->canResetPassword($actor, $user),
                'can_manage' => $canManageUsers && $userScope->canManageTarget($actor, $user),
                'roles' => $user->roles->map(function (Role $role) use ($zoneLabels, $centerLabels): array {
                    $zoneId = $role->pivot->zone_id ? (int) $role->pivot->zone_id : null;
                    $centerId = $role->pivot->center_id ? (int) $role->pivot->center_id : null;
                    $scopeLabel = 'Organization - SPK';
                    if ($centerId !== null) {
                        $center = $centerLabels->get($centerId);
                        $scopeLabel = $center ? 'Center - '.$center->name.' ('.$center->code.')' : 'Center';
                    } elseif ($zoneId !== null) {
                        $zone = $zoneLabels->get($zoneId);
                        $scopeLabel = $zone ? 'Zone - '.$zone->name.' ('.$zone->code.')' : 'Zone';
                    }

                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug,
                        'zone_id' => $zoneId,
                        'center_id' => $centerId,
                        'scope_label' => $scopeLabel,
                        'is_primary' => (bool) $role->pivot->is_primary,
                    ];
                })->values(),
            ]);

        if (! $canManageUsers) {
            return Inertia::render('admin/users', [
                'users' => $users,
                'roles' => [],
                'zones' => [],
                'centers' => [],
                'karyakars' => [],
                'canManageUsers' => false,
                'canResetPasswords' => $canResetPasswords,
                'userSearch' => $userSearch,
                'userListTruncated' => $userListTruncated,
            ]);
        }

        $centerIds = $scope->centers($actor)->pluck('centers.id')->map(fn ($id) => (int) $id)->all();

        return Inertia::render('admin/users', [
            'users' => $users,
            'roles' => $userScope->assignableRoles($actor)->orderBy('name')->get(['id', 'name', 'slug', 'module']),
            'zones' => $scope->zones($actor)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'centers' => Center::query()->whereIn('id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'zone_id', 'name', 'code']),
            'karyakars' => [],
            'canManageUsers' => true,
            'canResetPasswords' => $canResetPasswords,
            'userSearch' => $userSearch,
            'userListTruncated' => $userListTruncated,
        ]);
    }

    public function searchKaryakars(Request $request): JsonResponse
    {
        $data = $request->validate([
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        abort_unless($request->user()->hasPermission('manage_users'), 403);
        abort_unless($request->user()->canAccessCenterId((int) $data['center_id']), 403, 'Center is outside your user-management scope.');
        $search = trim((string) ($data['q'] ?? ''));
        $query = Karyakar::query()->where('center_id', (int) $data['center_id'])->where('status', 'approved')->whereNull('user_id');
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('full_name', 'ilike', '%'.$search.'%')->orWhere('karyakar_reference', 'ilike', '%'.$search.'%'));
        }
        return response()->json([
            'results' => $query->orderBy('full_name')->limit(50)->get(['id', 'center_id', 'user_id', 'full_name', 'karyakar_reference']),
        ]);
    }

    public function store(Request $request, AuditTrail $auditTrail, UserAdministrationScope $userScope): RedirectResponse
    {
        $data = $this->validatedCreate($request);
        $role = Role::query()->findOrFail($data['role_id']);
        $userScope->assertCanAssign($request->user(), $role, $this->nullableInt($data['zone_id'] ?? null), $this->nullableInt($data['center_id'] ?? null));

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
            $auditTrail->record('users', 'created', User::class, (string) $user->id, [], [
                'name' => $user->name,
                'email' => $user->email,
                'role_id' => $data['role_id'],
                'zone_id' => $data['zone_id'] ?? null,
                'center_id' => $data['center_id'] ?? null,
                'karyakar_id' => $data['karyakar_id'] ?? null,
            ]);
        }, 3);

        return back()->with('success', 'User created successfully.');
    }

    public function update(Request $request, User $user, AuditTrail $auditTrail, UserAdministrationScope $userScope): RedirectResponse
    {
        $user->loadMissing('roles');
        $userScope->assertCanManageTarget($request->user(), $user);
        $data = $this->validatedUpdate($request, $user);
        $role = Role::query()->findOrFail($data['role_id']);
        $userScope->assertCanAssign($request->user(), $role, $this->nullableInt($data['zone_id'] ?? null), $this->nullableInt($data['center_id'] ?? null));

        DB::transaction(function () use ($data, $user, $auditTrail): void {
            $old = [
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'role' => $user->primaryRole()?->slug,
            ];
            $user->update(['name' => $data['name'], 'email' => $data['email'], 'status' => $data['status']]);
            $this->syncPrimaryRole($user, $data);
            $this->syncKaryakarLink($user, $data);
            $auditTrail->record('users', 'updated', User::class, (string) $user->id, $old, [
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'role_id' => $data['role_id'],
                'zone_id' => $data['zone_id'] ?? null,
                'center_id' => $data['center_id'] ?? null,
                'karyakar_id' => $data['karyakar_id'] ?? null,
            ]);
        }, 3);

        return back()->with('success', 'User updated successfully.');
    }

    public function resetPassword(Request $request, User $user, AuditTrail $auditTrail, UserAdministrationScope $userScope): RedirectResponse
    {
        $user->loadMissing('roles');
        $actor = $request->user();
        $userScope->assertCanResetPassword($actor, $user);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Lock the target row so two administrators resetting the same account at the
        // same time cannot reuse the same session_version. Every successful reset must
        // advance the version and therefore revoke sessions created by an earlier reset.
        DB::transaction(function () use ($actor, $user, $data, $auditTrail, $userScope): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lockedUser->load('roles.permissions');
            $userScope->assertCanResetPassword($actor, $lockedUser);

            if (Hash::check($data['password'], $lockedUser->password)) {
                throw ValidationException::withMessages(['password' => 'The new password must be different from the current password.']);
            }

            $oldSessionVersion = (int) $lockedUser->session_version;
            $oldPasswordChangedAt = $lockedUser->password_changed_at?->toIso8601String();
            $newSessionVersion = max(1, $oldSessionVersion) + 1;
            $changedAt = now();

            $lockedUser->forceFill([
                'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60),
                'session_version' => $newSessionVersion,
                'password_changed_at' => $changedAt,
            ])->save();

            $role = $lockedUser->primaryRole();
            $auditTrail->record(
                'users',
                'password_reset',
                User::class,
                (string) $lockedUser->id,
                ['session_version' => $oldSessionVersion, 'password_changed_at' => $oldPasswordChangedAt],
                ['session_version' => $newSessionVersion, 'password_changed_at' => $changedAt->toIso8601String(), 'email' => $lockedUser->email],
                $data['reason'] ?? null,
                $role?->pivot?->zone_id,
                $role?->pivot?->center_id,
            );
        }, 3);

        if ($actor->is($user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->with('success', 'Password reset successfully. Sign in with your new password.');
        }

        return back()->with('success', "Password reset successfully for {$user->name}. Existing sessions have been revoked.");
    }

    private function validatedCreate(Request $request): array
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        return $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[\pL\pM\s.\'-]+$/u'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'role_id' => ['required', 'exists:roles,id'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'center_id' => ['nullable', 'integer', 'exists:centers,id'],
            'karyakar_id' => ['nullable', 'integer', 'exists:karyakars,id'],
        ]);
    }

    private function validatedUpdate(Request $request, User $user): array
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        return $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[\pL\pM\s.\'-]+$/u'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['prohibited'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'role_id' => ['required', 'exists:roles,id'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'center_id' => ['nullable', 'integer', 'exists:centers,id'],
            'karyakar_id' => ['nullable', 'integer', 'exists:karyakars,id'],
        ]);
    }

    private function syncPrimaryRole(User $user, array $data): void
    {
        $role = Role::query()->findOrFail($data['role_id']);
        $zoneId = $this->nullableInt($data['zone_id'] ?? null);
        $centerId = $this->nullableInt($data['center_id'] ?? null);

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
        $user->unsetRelation('roles');
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

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
