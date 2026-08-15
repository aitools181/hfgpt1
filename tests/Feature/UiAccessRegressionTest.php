<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\InactivityEvent;
use App\Models\Karyakar;
use App\Models\Role;
use App\Models\SankalpGroup;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UiAccessRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $user = User::query()->create([
            'name' => 'Regression Super Admin',
            'email' => 'regression-super-admin@example.test',
            'password' => 'StrongPassword123!',
            'status' => 'active',
        ]);
        $user->roles()->attach($role->id, ['zone_id' => null, 'center_id' => null, 'is_primary' => true]);
        return $user->fresh('roles.permissions');
    }

    public function test_super_admin_my_target_opens_without_linked_or_approved_karyakar(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/field/my-target')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('field/my-target')
                ->where('karyakar', null)
                ->has('adminChoices', 0)
                ->where('isSuperAdmin', true));
    }

    public function test_super_admin_reminders_page_opens_with_empty_dataset(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/field/reminders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('field/reminders')
                ->has('events.data', 0));
    }


    public function test_super_admin_reminders_page_serializes_existing_event(): void
    {
        $admin = $this->superAdmin();
        $zone = Zone::query()->create(['name' => 'Regression Zone', 'code' => 'RZ', 'status' => 'active']);
        $center = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Regression Center', 'code' => 'RC', 'status' => 'active']);
        $karyakar = Karyakar::query()->create([
            'center_id' => $center->id,
            'karyakar_reference' => 'SK-RC-000001',
            'source' => 'manual',
            'full_name' => 'Reminder Karyakar',
            'gender' => 'male',
            'age' => 35,
            'category' => 'Yuvak Karyakar',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
        $group = SankalpGroup::query()->create([
            'center_id' => $center->id,
            'group_code' => 'RC-001',
            'group_type' => 'two_male',
            'status' => 'active',
            'activated_at' => now()->subDays(8),
        ]);
        InactivityEvent::query()->create([
            'center_id' => $center->id,
            'group_id' => $group->id,
            'karyakar_id' => $karyakar->id,
            'recipient_user_id' => $admin->id,
            'event_type' => 'reminder',
            'inactivity_days' => 4,
            'status' => 'open',
            'activity_anchor_at' => now()->subDays(4),
            'triggered_at' => now(),
        ]);

        $this->actingAs($admin)->get('/field/reminders')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('field/reminders')
                ->has('events.data', 1)
                ->where('events.data.0.group.group_code', 'RC-001')
                ->where('events.data.0.karyakar.full_name', 'Reminder Karyakar'));
    }

    public function test_super_admin_bal_dashboard_and_analysis_open_without_bal_data(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/bal-pravruti')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bal/dashboard')
                ->where('bal.summary.activeGroups', 0));

        $this->actingAs($admin)->get('/bal-pravruti/analysis')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bal/analysis')
                ->where('analysis.summary.activeGroups', 0));
    }
}
