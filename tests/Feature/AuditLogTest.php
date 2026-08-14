<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_zone_creation_generates_audit_log(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $user = User::query()->create(['name' => 'Admin', 'email' => 'audit@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $user->roles()->attach($role->id, ['is_primary' => true]);

        $this->actingAs($user)->post('/admin/zones', ['name' => 'North', 'code' => 'NTH', 'status' => 'active'])->assertRedirect();

        $this->assertTrue(AuditLog::query()->where('module', 'zone')->where('action', 'created')->where('user_id', $user->id)->exists());
    }
}
