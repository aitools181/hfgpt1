<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\Family;
use App\Models\Role;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_center_admin_cannot_update_foreign_center(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $zone = Zone::query()->create(['name' => 'Zone A', 'code' => 'ZA', 'status' => 'active']);
        $own = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Own', 'code' => 'OWN', 'status' => 'active']);
        $foreign = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Foreign', 'code' => 'FOR', 'status' => 'active']);
        $role = Role::query()->where('slug', 'center_admin')->firstOrFail();
        $user = User::query()->create(['name' => 'Center Admin', 'email' => 'ca@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $user->roles()->attach($role->id, ['zone_id' => $zone->id, 'center_id' => $own->id, 'is_primary' => true]);

        $this->actingAs($user)->put("/admin/centers/{$foreign->id}", [
            'zone_id' => $zone->id, 'name' => 'Changed', 'code' => 'FOR', 'city' => null,
            'address' => null, 'contact_phone' => null, 'contact_email' => null, 'status' => 'active',
        ])->assertForbidden();
    }


    public function test_center_scoped_roles_do_not_inherit_zone_wide_center_access(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $zone = Zone::query()->create(['name' => 'Shared Zone', 'code' => 'SZ', 'status' => 'active']);
        $own = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Own Center', 'code' => 'OC', 'status' => 'active']);
        $foreign = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Foreign Center', 'code' => 'FC', 'status' => 'active']);
        $role = Role::query()->where('slug', 'center_admin')->firstOrFail();
        $user = User::query()->create(['name' => 'Scoped Center Admin', 'email' => 'scoped-center@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $user->roles()->attach($role->id, ['zone_id' => $zone->id, 'center_id' => $own->id, 'is_primary' => true]);
        Family::query()->create(['center_id' => $own->id, 'manual_reference' => 'HF-OC-1', 'source' => 'manual', 'head_name' => 'Own Family', 'status' => 'active']);
        $foreignFamily = Family::query()->create(['center_id' => $foreign->id, 'manual_reference' => 'HF-FC-1', 'source' => 'manual', 'head_name' => 'Foreign Family', 'status' => 'active']);

        $this->assertTrue($user->fresh('roles.permissions')->canAccessCenterId($own->id));
        $this->assertFalse($user->fresh('roles.permissions')->canAccessCenterId($foreign->id));
        $this->actingAs($user)->get("/registration/families/{$foreignFamily->id}")->assertForbidden();
        $this->actingAs($user)->get('/registration/families')->assertOk()->assertInertia(fn ($page) => $page
            ->has('families.data', 1)
            ->where('families.data.0.head_name', 'Own Family'));
    }

    public function test_zonal_admin_retains_zone_wide_center_access(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $zone = Zone::query()->create(['name' => 'Zonal Scope', 'code' => 'ZS', 'status' => 'active']);
        $one = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Center One', 'code' => 'C1', 'status' => 'active']);
        $two = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Center Two', 'code' => 'C2', 'status' => 'active']);
        $role = Role::query()->where('slug', 'zonal_admin')->firstOrFail();
        $user = User::query()->create(['name' => 'Zonal Admin', 'email' => 'zonal-scope@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $user->roles()->attach($role->id, ['zone_id' => $zone->id, 'center_id' => null, 'is_primary' => true]);

        $user = $user->fresh('roles.permissions');
        $this->assertTrue($user->canAccessCenterId($one->id));
        $this->assertTrue($user->canAccessCenterId($two->id));
        $this->assertTrue($user->canAccessZoneId($zone->id));
    }
}
