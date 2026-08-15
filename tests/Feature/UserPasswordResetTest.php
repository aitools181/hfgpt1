<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Center;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_reset_any_user_password_and_audit_contains_no_password(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$zone, $center] = $this->organization();
        $admin = $this->userWithRole('super_admin', 'admin-reset@example.test');
        $target = $this->userWithRole('karyakar', 'target-reset@example.test', $zone, $center);

        $this->actingAs($admin)->put("/admin/users/{$target->id}/password", [
            'password' => 'NewStrongPassword456!',
            'password_confirmation' => 'NewStrongPassword456!',
            'reason' => 'User requested reset',
        ])->assertRedirect();

        $target->refresh();
        $this->assertTrue(Hash::check('NewStrongPassword456!', $target->password));
        $this->assertSame(2, (int) $target->session_version);
        $this->assertNotNull($target->password_changed_at);

        $log = AuditLog::query()->where('action', 'password_reset')->where('record_id', (string) $target->id)->firstOrFail();
        $this->assertSame('User requested reset', $log->reason);
        $this->assertArrayNotHasKey('password', $log->old_values ?? []);
        $this->assertArrayNotHasKey('password', $log->new_values ?? []);
        $this->assertStringNotContainsString('NewStrongPassword456!', json_encode([$log->old_values, $log->new_values, $log->reason]));
    }


    public function test_super_admin_can_reset_own_password_and_is_signed_out(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = $this->userWithRole('super_admin', 'self-reset@example.test');

        $this->actingAs($admin)->put("/admin/users/{$admin->id}/password", [
            'password' => 'SelfResetPassword456!',
            'password_confirmation' => 'SelfResetPassword456!',
        ])->assertRedirect('/login')->assertSessionHas('success');

        $this->assertGuest();
        $this->assertTrue(Hash::check('SelfResetPassword456!', $admin->fresh()->password));
    }

    public function test_role_without_password_reset_permission_is_forbidden(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$zone, $center] = $this->organization();
        $actor = $this->userWithRole('center_admin', 'no-reset-permission@example.test', $zone, $center);
        $target = $this->userWithRole('karyakar', 'no-reset-target@example.test', $zone, $center);

        $this->actingAs($actor)->put("/admin/users/{$target->id}/password", [
            'password' => 'NewStrongPassword456!',
            'password_confirmation' => 'NewStrongPassword456!',
        ])->assertForbidden();
    }

    public function test_granted_center_role_can_reset_equal_or_lower_rank_only_inside_its_center(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$zone, $center] = $this->organization();
        $otherCenter = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Other Center', 'code' => 'OTH', 'status' => 'active']);
        $actor = $this->userWithRole('center_admin', 'scoped-reset@example.test', $zone, $center);
        $ownTarget = $this->userWithRole('karyakar', 'own-reset@example.test', $zone, $center);
        $foreignTarget = $this->userWithRole('karyakar', 'foreign-reset@example.test', $zone, $otherCenter);
        $this->grantResetPermission('center_admin');
        $actor = $actor->fresh('roles.permissions');

        $this->actingAs($actor)->put("/admin/users/{$ownTarget->id}/password", [
            'password' => 'ScopedStrongPassword456!',
            'password_confirmation' => 'ScopedStrongPassword456!',
        ])->assertRedirect();

        $this->actingAs($actor)->put("/admin/users/{$foreignTarget->id}/password", [
            'password' => 'ForeignStrongPassword456!',
            'password_confirmation' => 'ForeignStrongPassword456!',
        ])->assertForbidden();
    }

    public function test_non_super_admin_cannot_reset_super_admin_or_higher_scope_user(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$zone, $center] = $this->organization();
        $actor = $this->userWithRole('center_admin', 'center-reset@example.test', $zone, $center);
        $superAdmin = $this->userWithRole('super_admin', 'protected-super@example.test');
        $zonalAdmin = $this->userWithRole('zonal_admin', 'protected-zonal@example.test', $zone);
        $this->grantResetPermission('center_admin');
        $actor = $actor->fresh('roles.permissions');

        foreach ([$superAdmin, $zonalAdmin] as $target) {
            $this->actingAs($actor)->put("/admin/users/{$target->id}/password", [
                'password' => 'ProtectedStrongPassword456!',
                'password_confirmation' => 'ProtectedStrongPassword456!',
            ])->assertForbidden();
        }
    }

    public function test_password_reset_permission_can_be_assigned_from_role_permission_matrix(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = $this->userWithRole('super_admin', 'permission-admin@example.test');
        $role = Role::query()->where('slug', 'center_admin')->firstOrFail();
        $permission = Permission::query()->where('slug', 'reset_user_passwords')->firstOrFail();
        $this->assertFalse($role->permissions()->whereKey($permission->id)->exists());

        $ids = $role->permissions()->pluck('permissions.id')->push($permission->id)->unique()->values()->all();
        $this->actingAs($admin)->put("/admin/settings/roles/{$role->id}/permissions", [
            'permission_ids' => $ids,
        ])->assertRedirect();

        $this->assertTrue($role->permissions()->whereKey($permission->id)->exists());
    }


    public function test_non_super_role_manager_cannot_grant_or_remove_password_reset_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $bnAdmin = $this->userWithRole('bn_karyalay_admin', 'bn-role-manager@example.test');
        $superAdmin = $this->userWithRole('super_admin', 'super-role-manager@example.test');
        $role = Role::query()->where('slug', 'center_admin')->firstOrFail();
        $permission = Permission::query()->where('slug', 'reset_user_passwords')->firstOrFail();

        $grantIds = $role->permissions()->pluck('permissions.id')->push($permission->id)->unique()->values()->all();
        $this->actingAs($bnAdmin)->put("/admin/settings/roles/{$role->id}/permissions", [
            'permission_ids' => $grantIds,
        ])->assertForbidden();
        $this->assertFalse($role->permissions()->whereKey($permission->id)->exists());

        $this->actingAs($superAdmin)->put("/admin/settings/roles/{$role->id}/permissions", [
            'permission_ids' => $grantIds,
        ])->assertRedirect();
        $this->assertTrue($role->permissions()->whereKey($permission->id)->exists());

        $removeIds = $role->permissions()->whereKeyNot($permission->id)->pluck('permissions.id')->all();
        $this->actingAs($bnAdmin)->put("/admin/settings/roles/{$role->id}/permissions", [
            'permission_ids' => $removeIds,
        ])->assertForbidden();
        $this->assertTrue($role->permissions()->whereKey($permission->id)->exists());
    }

    public function test_reset_only_role_sees_only_resettable_users_in_its_scope(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$zone, $center] = $this->organization();
        $actor = $this->userWithRole('karyakar', 'reset-only@example.test', $zone, $center);
        $peer = $this->userWithRole('karyakar', 'peer@example.test', $zone, $center);
        $this->userWithRole('center_admin', 'higher-admin@example.test', $zone, $center);
        $this->grantResetPermission('karyakar');
        $actor = $actor->fresh('roles.permissions');

        $expectedEmails = collect([$actor->email, $peer->email])->sort()->values()->all();
        $this->actingAs($actor)->get('/admin/users')->assertOk()->assertInertia(fn ($page) => $page
            ->where('canManageUsers', false)
            ->where('canResetPasswords', true)
            ->has('users', 2)
            ->where('users', fn ($users) => collect($users)->pluck('email')->sort()->values()->all() === $expectedEmails)
        );
    }

    public function test_manage_users_update_cannot_bypass_password_reset_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$zone, $center] = $this->organization();
        $admin = $this->userWithRole('super_admin', 'update-admin@example.test');
        $target = $this->userWithRole('karyakar', 'update-target@example.test', $zone, $center);
        $karyakarRole = Role::query()->where('slug', 'karyakar')->firstOrFail();

        $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'password' => 'SmuggledPassword456!',
            'status' => 'active',
            'role_id' => $karyakarRole->id,
            'center_id' => $center->id,
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('StrongPassword123!', $target->fresh()->password));
    }


    public function test_delegated_manage_users_cannot_escalate_role_or_cross_center_scope(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$zone, $center] = $this->organization();
        $otherCenter = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Foreign Center', 'code' => 'FRN', 'status' => 'active']);
        $actor = $this->userWithRole('center_admin', 'delegated-manager@example.test', $zone, $center);
        $this->grantPermission('center_admin', 'manage_users');
        $actor = $actor->fresh('roles.permissions');

        $superRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $karyakarRole = Role::query()->where('slug', 'karyakar')->firstOrFail();

        $this->actingAs($actor)->post('/admin/users', [
            'name' => 'Escalated Admin',
            'email' => 'escalated@example.test',
            'password' => 'StrongPassword123!',
            'status' => 'active',
            'role_id' => $superRole->id,
        ])->assertForbidden();

        $this->actingAs($actor)->post('/admin/users', [
            'name' => 'Foreign User',
            'email' => 'foreign-user@example.test',
            'password' => 'StrongPassword123!',
            'status' => 'active',
            'role_id' => $karyakarRole->id,
            'center_id' => $otherCenter->id,
        ])->assertForbidden();

        $this->actingAs($actor)->post('/admin/users', [
            'name' => 'Own Center User',
            'email' => 'own-user@example.test',
            'password' => 'StrongPassword123!',
            'status' => 'active',
            'role_id' => $karyakarRole->id,
            'center_id' => $center->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'own-user@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'escalated@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'foreign-user@example.test']);
    }

    public function test_password_reset_revokes_stale_authenticated_session(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$zone, $center] = $this->organization();
        $target = $this->userWithRole('karyakar', 'session-target@example.test', $zone, $center);

        $this->actingAs($target)->withSession(['auth_session_version' => 1])->get('/')->assertOk();

        $target->forceFill([
            'session_version' => 2,
            'password_changed_at' => now(),
        ])->save();

        $this->actingAs($target)->withSession(['auth_session_version' => 1])->get('/')
            ->assertRedirect('/login')
            ->assertSessionHas('error');
        $this->assertGuest();
    }

    private function organization(): array
    {
        $zone = Zone::query()->create(['name' => 'Reset Zone', 'code' => 'RZ', 'status' => 'active']);
        $center = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Reset Center', 'code' => 'RST', 'status' => 'active']);
        return [$zone, $center];
    }

    private function userWithRole(string $roleSlug, string $email, ?Zone $zone = null, ?Center $center = null): User
    {
        $user = User::query()->create([
            'name' => str($roleSlug)->replace('_', ' ')->title()->toString(),
            'email' => $email,
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, [
            'zone_id' => $roleSlug === 'zonal_admin' ? $zone?->id : $center?->zone_id,
            'center_id' => in_array($roleSlug, ['super_admin', 'bn_karyalay_admin', 'zonal_admin'], true) ? null : $center?->id,
            'is_primary' => true,
        ]);
        return $user->fresh('roles.permissions');
    }

    private function grantResetPermission(string $roleSlug): void
    {
        $this->grantPermission($roleSlug, 'reset_user_passwords');
    }

    private function grantPermission(string $roleSlug, string $permissionSlug): void
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $permission = Permission::query()->where('slug', $permissionSlug)->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
}
