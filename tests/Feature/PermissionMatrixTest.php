<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_required_roles_exist_and_viewer_is_removed(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $required = ['super_admin','bn_karyalay_admin','zonal_admin','center_admin','computer_op','karyakar','nirdeshak','nirikshak','sanchalak'];
        $this->assertSameCanonicalizing($required, Role::query()->pluck('slug')->all());
        $this->assertDatabaseMissing('roles', ['slug' => 'viewer']);
    }

    public function test_field_role_does_not_gain_administrative_support_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::query()->where('slug', 'karyakar')->with('permissions')->firstOrFail();
        $slugs = $role->permissions->pluck('slug');
        $this->assertTrue($slugs->contains('view_shared_content'));
        $this->assertTrue($slugs->contains('use_sticky_notes'));
        $this->assertFalse($slugs->contains('manage_shared_content'));
        $this->assertFalse($slugs->contains('manage_inventory'));
        $this->assertFalse($slugs->contains('manage_support'));
    }
}
