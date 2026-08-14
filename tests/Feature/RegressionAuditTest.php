<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Center;
use App\Models\CorrectionRequest;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\GroupFamilyAssignment;
use App\Models\InventoryItem;
use App\Models\FamilyTimeCompletion;
use App\Models\FamilyTimeSchedule;
use App\Models\Karyakar;
use App\Models\SamparkArea;
use App\Models\Society;
use App\Models\SankalpGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportRequest;
use App\Models\Target;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegressionAuditTest extends TestCase
{
    use RefreshDatabase;

    private function setupOrg(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $zone = Zone::query()->create(['name' => 'Audit Zone', 'code' => 'AZ', 'status' => 'active']);
        $center = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Audit Center', 'code' => 'AUD', 'status' => 'active']);
        return [$zone, $center];
    }

    private function user(string $roleSlug, string $email, Zone $zone, ?Center $center = null): User
    {
        $user = User::query()->create([
            'name' => $roleSlug,
            'email' => $email,
            'password' => 'StrongPassword123!',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, [
            'zone_id' => $center?->zone_id ?? ($roleSlug === 'super_admin' ? null : $zone->id),
            'center_id' => $center?->id,
            'is_primary' => true,
        ]);
        return $user->fresh('roles.permissions');
    }

    private function approvedKaryakar(Center $center, string $reference, ?User $user = null): Karyakar
    {
        return Karyakar::query()->create([
            'center_id' => $center->id,
            'user_id' => $user?->id,
            'karyakar_reference' => $reference,
            'source' => 'manual',
            'full_name' => $reference,
            'gender' => 'male',
            'age' => 35,
            'category' => 'Yuvak Karyakar',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    public function test_reseeding_preserves_existing_role_permission_customization(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::query()->where('slug', 'center_admin')->firstOrFail();
        $permission = Permission::query()->where('slug', 'manage_family_time')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->seed(RolePermissionSeeder::class);

        $this->assertFalse($role->fresh('permissions')->permissions->contains('slug', 'manage_family_time'));
    }

    public function test_center_admin_cannot_create_organization_wide_family_time_schedule(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'center-family-time@example.test', $zone, $center);

        $this->actingAs($admin)->post('/support/family-time/schedules', [
            'center_id' => null,
            'title' => 'Global schedule attempt',
            'audience' => 'all',
            'starts_at' => now()->toDateTimeString(),
            'status' => 'active',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('family_time_schedules', ['title' => 'Global schedule attempt']);
    }

    public function test_center_admin_does_not_see_other_organization_level_family_time_completions(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'center-family-completion@example.test', $zone, $center);
        $super = $this->user('super_admin', 'super-family-completion@example.test', $zone);
        $schedule = FamilyTimeSchedule::query()->create([
            'center_id' => null,
            'title' => 'Organization Schedule',
            'audience' => 'all',
            'starts_at' => now(),
            'status' => 'active',
            'created_by' => $super->id,
        ]);
        FamilyTimeCompletion::query()->create([
            'family_time_schedule_id' => $schedule->id,
            'user_id' => $super->id,
            'center_id' => null,
            'completed_on' => now()->toDateString(),
        ]);

        $this->actingAs($admin)->get('/support/family-time')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('completions', 0));
    }

    public function test_center_admin_cannot_read_or_update_organization_level_support_ticket(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'center-support@example.test', $zone, $center);
        $super = $this->user('super_admin', 'super-support@example.test', $zone);
        $ticket = SupportRequest::query()->create([
            'user_id' => $super->id,
            'center_id' => null,
            'subject' => 'Organization private request',
            'category' => 'technical',
            'message' => 'Karyalay-only support request',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $this->actingAs($admin)->get('/support/contact')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('requests', 0));

        $this->actingAs($admin)->put("/support/contact/{$ticket->id}", [
            'status' => 'resolved',
            'response_note' => 'Should not be allowed',
        ])->assertForbidden();
    }

    public function test_import_upload_is_saved_on_private_local_disk_and_tsv_is_accepted(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'import-private@example.test', $zone, $center);
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent(
            'families.tsv',
            "family_id\thead_name\tmember_id\tmember_name\tgender\tage\nF-1\tPatel Family\tM-1\tRaj Patel\tmale\t35\n"
        );

        $this->actingAs($admin)->post('/registration/imports', [
            'center_id' => $center->id,
            'type' => 'families',
            'file' => $file,
        ])->assertRedirect();

        $this->assertDatabaseHas('families', ['center_id' => $center->id, 'external_family_id' => 'F-1']);
        $batch = \App\Models\ImportBatch::query()->firstOrFail();
        Storage::disk('local')->assertExists($batch->stored_path);
    }


    public function test_changing_user_away_from_field_role_removes_stale_karyakar_link(): void
    {
        [$zone, $center] = $this->setupOrg();
        $super = $this->user('super_admin', 'role-editor@example.test', $zone);
        $field = $this->user('karyakar', 'field-role@example.test', $zone, $center);
        $karyakar = Karyakar::query()->create([
            'center_id' => $center->id,
            'user_id' => $field->id,
            'karyakar_reference' => 'SK-AUD-000001',
            'source' => 'manual',
            'full_name' => 'Field Role',
            'gender' => 'male',
            'age' => 35,
            'category' => 'Yuvak Karyakar',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
        $centerAdminRole = Role::query()->where('slug', 'center_admin')->firstOrFail();

        $this->actingAs($super)->put("/admin/users/{$field->id}", [
            'name' => $field->name,
            'email' => $field->email,
            'password' => '',
            'status' => 'active',
            'role_id' => $centerAdminRole->id,
            'zone_id' => $zone->id,
            'center_id' => $center->id,
            'karyakar_id' => $karyakar->id,
        ])->assertRedirect();

        $this->assertNull($karyakar->fresh()->user_id);
    }

    public function test_center_and_zone_codes_are_normalized_before_unique_validation(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $zone = Zone::query()->create(['name' => 'Existing Zone', 'code' => 'NZ', 'status' => 'active']);
        $super = $this->user('super_admin', 'code-admin@example.test', $zone);
        Center::query()->create(['zone_id' => $zone->id, 'name' => 'Existing Center', 'code' => 'GND', 'status' => 'active']);

        $this->actingAs($super)->post('/admin/centers', [
            'zone_id' => $zone->id,
            'name' => 'Duplicate Center Code',
            'code' => 'gnd',
            'status' => 'active',
        ])->assertSessionHasErrors('code');

        $this->actingAs($super)->post('/admin/zones', [
            'name' => 'Duplicate Zone Code',
            'code' => 'nz',
            'status' => 'active',
        ])->assertSessionHasErrors('code');
    }

    public function test_target_creation_rejects_incomplete_draft_group(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'draft-target@example.test', $zone, $center);
        $group = SankalpGroup::query()->create([
            'center_id' => $center->id,
            'group_code' => 'AUD-001',
            'group_type' => 'two_male',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        $area = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Area 1', 'status' => 'active']);

        $this->actingAs($admin)->post('/assignments/targets', [
            'center_id' => $center->id,
            'group_id' => $group->id,
            'sampark_area_id' => $area->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'target_quantity' => 10,
        ])->assertSessionHasErrors('group_id');
    }

    public function test_email_verified_at_is_mass_assignable_for_bootstrap_and_admin_created_users(): void
    {
        $user = User::query()->create([
            'name' => 'Verified',
            'email' => 'verified@example.test',
            'password' => 'StrongPassword123!',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_active_group_rejects_new_remaining_family_report(): void
    {
        [$zone, $center] = $this->setupOrg();
        $fieldUser = $this->user('karyakar', 'active-report-field@example.test', $zone, $center);
        $field = $this->approvedKaryakar($center, 'SK-AUD-AR-1', $fieldUser);
        $partner = $this->approvedKaryakar($center, 'SK-AUD-AR-2');
        $group = SankalpGroup::query()->create([
            'center_id' => $center->id,
            'group_code' => 'AUD-ACTIVE-1',
            'group_type' => 'two_male',
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $group->karyakarAssignments()->create(['karyakar_id' => $field->id, 'position' => 1, 'status' => 'active', 'assigned_at' => now()]);
        $group->karyakarAssignments()->create(['karyakar_id' => $partner->id, 'position' => 2, 'status' => 'active', 'assigned_at' => now()]);

        $this->actingAs($fieldUser)->post("/assignments/groups/{$group->id}/remaining-family/report", [
            'head_name' => 'Should Not Be Created',
            'head_mobile' => '9999999999',
        ])->assertSessionHasErrors('group');

        $this->assertDatabaseMissing('families', ['head_name' => 'Should Not Be Created']);
    }

    public function test_zonal_admin_can_see_own_organization_level_support_ticket_but_not_another_users(): void
    {
        [$zone] = $this->setupOrg();
        $zonal = $this->user('zonal_admin', 'zonal-support-own@example.test', $zone);
        $super = $this->user('super_admin', 'super-support-other@example.test', $zone);
        SupportRequest::query()->create([
            'user_id' => $zonal->id, 'center_id' => null, 'subject' => 'Own zonal ticket',
            'category' => 'technical', 'message' => 'Own', 'priority' => 'normal', 'status' => 'open',
        ]);
        SupportRequest::query()->create([
            'user_id' => $super->id, 'center_id' => null, 'subject' => 'Other organization ticket',
            'category' => 'technical', 'message' => 'Other', 'priority' => 'normal', 'status' => 'open',
        ]);

        $this->actingAs($zonal)->get('/support/contact')->assertOk()->assertInertia(fn ($page) => $page
            ->has('requests', 1)
            ->where('requests.0.subject', 'Own zonal ticket'));
    }

    public function test_zonal_admin_can_see_own_global_family_time_completion_without_seeing_others(): void
    {
        [$zone] = $this->setupOrg();
        $zonal = $this->user('zonal_admin', 'zonal-family-own@example.test', $zone);
        $super = $this->user('super_admin', 'super-family-other@example.test', $zone);
        $schedule = FamilyTimeSchedule::query()->create([
            'center_id' => null, 'title' => 'Global Family Time', 'audience' => 'all',
            'starts_at' => now(), 'status' => 'active', 'created_by' => $super->id,
        ]);
        FamilyTimeCompletion::query()->create([
            'family_time_schedule_id' => $schedule->id, 'user_id' => $zonal->id,
            'center_id' => null, 'completed_on' => now()->toDateString(),
        ]);
        FamilyTimeCompletion::query()->create([
            'family_time_schedule_id' => $schedule->id, 'user_id' => $super->id,
            'center_id' => null, 'completed_on' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($zonal)->get('/support/family-time')->assertOk()->assertInertia(fn ($page) => $page
            ->has('completions', 1)
            ->where('completions.0.user_id', $zonal->id));
    }

    public function test_inventory_sku_is_normalized_and_case_variant_duplicate_is_validation_error(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'inventory-sku@example.test', $zone, $center);

        $this->actingAs($admin)->post('/support/inventory', [
            'center_id' => $center->id, 'sku' => ' book-1 ', 'name' => 'Book', 'unit' => 'pcs',
            'minimum_stock' => 0, 'status' => 'active',
        ])->assertRedirect();
        $this->assertDatabaseHas('inventory_items', ['center_id' => $center->id, 'sku' => 'BOOK-1']);

        $this->actingAs($admin)->post('/support/inventory', [
            'center_id' => $center->id, 'sku' => 'book-1', 'name' => 'Duplicate Book', 'unit' => 'pcs',
            'minimum_stock' => 0, 'status' => 'active',
        ])->assertSessionHasErrors('sku');
        $this->assertSame(1, InventoryItem::query()->where('center_id', $center->id)->count());
    }

    public function test_admin_user_email_is_canonicalized_and_case_variant_duplicate_is_rejected(): void
    {
        [$zone, $center] = $this->setupOrg();
        $super = $this->user('super_admin', 'email-admin@example.test', $zone);
        $centerAdminRole = Role::query()->where('slug', 'center_admin')->firstOrFail();

        $this->actingAs($super)->post('/admin/users', [
            'name' => 'Mixed Email', 'email' => ' Mixed.User@Example.Test ', 'password' => 'StrongPassword123!',
            'status' => 'active', 'role_id' => $centerAdminRole->id, 'zone_id' => $zone->id, 'center_id' => $center->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'mixed.user@example.test']);

        $this->actingAs($super)->post('/admin/users', [
            'name' => 'Duplicate Email', 'email' => 'MIXED.USER@EXAMPLE.TEST', 'password' => 'StrongPassword123!',
            'status' => 'active', 'role_id' => $centerAdminRole->id, 'zone_id' => $zone->id, 'center_id' => $center->id,
        ])->assertSessionHasErrors('email');
    }

    public function test_login_email_is_normalized_before_authentication(): void
    {
        [$zone, $center] = $this->setupOrg();
        $user = $this->user('center_admin', 'normalized.login@example.test', $zone, $center);
        $user->forceFill(['password' => \Illuminate\Support\Facades\Hash::make('StrongPassword123!')])->save();

        $this->post('/login', ['email' => ' NORMALIZED.LOGIN@EXAMPLE.TEST ', 'password' => 'StrongPassword123!'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_target_area_and_society_must_match_active_group_assignment(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'target-area@example.test', $zone, $center);
        $areaOne = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Area One', 'status' => 'active']);
        $areaTwo = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Area Two', 'status' => 'active']);
        $societyOne = Society::query()->create(['center_id' => $center->id, 'sampark_area_id' => $areaOne->id, 'name' => 'Society One', 'status' => 'active']);
        $group = SankalpGroup::query()->create([
            'center_id' => $center->id, 'sampark_area_id' => $areaOne->id, 'society_id' => $societyOne->id,
            'group_code' => 'AUD-TARGET-1', 'group_type' => 'two_male', 'status' => 'active', 'activated_at' => now(),
        ]);

        $base = ['center_id' => $center->id, 'group_id' => $group->id, 'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(), 'target_quantity' => 10];
        $this->actingAs($admin)->post('/assignments/targets', [...$base, 'sampark_area_id' => $areaTwo->id])
            ->assertSessionHasErrors('sampark_area_id');
        $this->actingAs($admin)->post('/assignments/targets', [...$base, 'sampark_area_id' => $areaOne->id, 'society_id' => null])
            ->assertSessionHasErrors('society_id');
    }

    public function test_fixed_family_assignment_requires_manage_fixed_permission_even_if_transfer_permission_exists(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'fixed-permission@example.test', $zone, $center);
        $role = Role::query()->where('slug', 'center_admin')->firstOrFail();
        $fixedPermission = Permission::query()->where('slug', 'manage_fixed_families')->firstOrFail();
        $role->permissions()->detach($fixedPermission->id);
        $admin->load('roles.permissions');
        $group = SankalpGroup::query()->create([
            'center_id' => $center->id, 'group_code' => 'AUD-FIXED-1', 'group_type' => 'two_male', 'status' => 'draft',
        ]);
        $family = Family::query()->create([
            'center_id' => $center->id, 'manual_reference' => 'HF-AUD-FIX-1', 'source' => 'manual',
            'head_name' => 'Fixed Permission Family', 'status' => 'active', 'registered_at' => now(),
        ]);

        $this->actingAs($admin)->post("/assignments/groups/{$group->id}/families", [
            'family_id' => $family->id, 'assignment_type' => 'fixed',
        ])->assertForbidden();
        $this->assertDatabaseMissing('group_family_assignments', ['family_id' => $family->id, 'status' => 'active']);
    }

    public function test_inactive_authenticated_user_is_forced_out_on_next_request(): void
    {
        [$zone, $center] = $this->setupOrg();
        $user = $this->user('center_admin', 'inactive-session@example.test', $zone, $center);
        $user->update(['status' => 'inactive']);

        $this->actingAs($user)->get('/')
            ->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_group_activation_rejects_pending_remaining_family_report(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'pending-report-activation@example.test', $zone, $center);
        $group = SankalpGroup::query()->create([
            'center_id' => $center->id, 'group_code' => 'AUD-PENDING-1', 'group_type' => 'two_male',
            'status' => 'draft', 'created_by' => $admin->id,
        ]);
        $family = Family::query()->create([
            'center_id' => $center->id, 'manual_reference' => 'HF-AUD-PENDING-1', 'source' => 'karyakar_reported',
            'head_name' => 'Pending Report Family', 'status' => 'pending_verification', 'registered_at' => now(),
        ]);
        $karyakar = $this->approvedKaryakar($center, 'SK-AUD-PENDING-1');
        \App\Models\RemainingFamilyReport::query()->create([
            'group_id' => $group->id, 'family_id' => $family->id, 'karyakar_id' => $karyakar->id,
            'status' => 'pending', 'reported_at' => now(),
        ]);

        $this->actingAs($admin)->post("/assignments/groups/{$group->id}/activate")
            ->assertSessionHasErrors('families');
        $this->assertSame('draft', $group->fresh()->status);
    }

    public function test_manual_family_society_requires_matching_area(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'family-location@example.test', $zone, $center);
        $area = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Family Area', 'status' => 'active']);
        $society = Society::query()->create(['center_id' => $center->id, 'sampark_area_id' => $area->id, 'name' => 'Family Society', 'status' => 'active']);

        $this->actingAs($admin)->post('/registration/families', [
            'center_id' => $center->id,
            'head_name' => 'Invalid Location Family',
            'society_id' => $society->id,
            'members' => [],
        ])->assertSessionHasErrors('sampark_area_id');

        $this->assertDatabaseMissing('families', ['head_name' => 'Invalid Location Family']);
    }

    public function test_area_import_rolls_back_whole_row_when_society_write_fails(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'area-import-atomic@example.test', $zone, $center);
        $existingArea = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Existing Area', 'external_code' => 'EA1', 'status' => 'active']);
        Society::query()->create(['center_id' => $center->id, 'sampark_area_id' => $existingArea->id, 'name' => 'Existing Society', 'external_code' => 'DUP-S', 'status' => 'active']);
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent(
            'areas.csv',
            "area_name,area_code,society_name,society_code\nNew Atomic Area,NA1,New Atomic Society,DUP-S\n"
        );

        $this->actingAs($admin)->post('/registration/imports', [
            'center_id' => $center->id,
            'type' => 'areas',
            'file' => $file,
        ])->assertRedirect();

        $this->assertDatabaseMissing('sampark_areas', ['center_id' => $center->id, 'name' => 'New Atomic Area']);
        $this->assertDatabaseHas('import_batches', ['center_id' => $center->id, 'status' => 'completed_with_errors', 'skipped_rows' => 1]);
    }


    public function test_approval_workflow_cannot_reject_an_already_approved_karyakar(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'approval-state@example.test', $zone, $center);
        $karyakar = $this->approvedKaryakar($center, 'SK-AUD-APPROVED-1');

        $this->actingAs($admin)->post("/registration/karyakars/{$karyakar->id}/decision", [
            'decision' => 'rejected',
            'decision_note' => 'Invalid re-decision attempt',
        ])->assertSessionHasErrors('decision');

        $this->assertSame('approved', $karyakar->fresh()->status);
    }


    public function test_family_master_and_member_edit_is_center_scoped_and_records_change_reason(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'family-edit@example.test', $zone, $center);
        $area = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Edit Area', 'status' => 'active']);
        $society = Society::query()->create(['center_id' => $center->id, 'sampark_area_id' => $area->id, 'name' => 'Edit Society', 'status' => 'active']);
        $family = Family::query()->create([
            'center_id' => $center->id, 'manual_reference' => 'HF-AUD-EDIT-1', 'source' => 'manual',
            'head_name' => 'Old Head', 'status' => 'active', 'registered_at' => now(),
        ]);
        $member = FamilyMember::query()->create([
            'family_id' => $family->id, 'name' => 'Old Member', 'gender' => 'male', 'age' => 40,
            'is_head' => true, 'status' => 'active',
        ]);

        $this->actingAs($admin)->put("/registration/families/{$family->id}", [
            'head_name' => 'Updated Head', 'head_mobile' => '9999999999', 'address' => 'Updated Address',
            'city_village' => 'Updated City', 'sampark_area_id' => $area->id, 'society_id' => $society->id,
            'status' => 'active', 'change_reason' => 'Verified family correction',
            'members' => [[
                'id' => $member->id, 'name' => 'Updated Member', 'gender' => 'male', 'age' => 40,
                'mobile' => '8888888888', 'relationship' => 'Head', 'is_head' => true, 'status' => 'active',
            ]],
        ])->assertRedirect();

        $this->assertDatabaseHas('families', ['id' => $family->id, 'head_name' => 'Updated Head', 'society_id' => $society->id]);
        $this->assertDatabaseHas('family_members', ['id' => $member->id, 'name' => 'Updated Member']);
        $this->assertTrue(AuditLog::query()->where('module', 'family')->where('record_id', (string) $family->id)->where('reason', 'Verified family correction')->exists());
        $this->assertTrue(AuditLog::query()->where('module', 'family_member')->where('record_id', (string) $member->id)->where('reason', 'Verified family correction')->exists());
    }

    public function test_family_cannot_be_deactivated_while_it_has_an_active_group_assignment(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'family-active-assignment@example.test', $zone, $center);
        $family = Family::query()->create([
            'center_id' => $center->id, 'manual_reference' => 'HF-AUD-ACTIVE-ASSIGN', 'source' => 'manual',
            'head_name' => 'Assigned Family', 'status' => 'active', 'registered_at' => now(),
        ]);
        $group = SankalpGroup::query()->create([
            'center_id' => $center->id, 'group_code' => 'AUD-FAMILY-LOCK', 'group_type' => 'two_male', 'status' => 'active', 'activated_at' => now(),
        ]);
        GroupFamilyAssignment::query()->create([
            'group_id' => $group->id, 'family_id' => $family->id, 'slot_number' => 1,
            'assignment_type' => 'fixed', 'assignment_source' => 'admin', 'status' => 'active', 'assigned_at' => now(),
        ]);

        $this->actingAs($admin)->put("/registration/families/{$family->id}", [
            'head_name' => $family->head_name, 'status' => 'inactive', 'change_reason' => 'Attempt inactive', 'members' => [],
        ])->assertSessionHasErrors('status');
        $this->assertSame('active', $family->fresh()->status);
    }

    public function test_linked_karyakar_member_age_gender_change_requires_correction_request(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'linked-member-edit@example.test', $zone, $center);
        $family = Family::query()->create([
            'center_id' => $center->id, 'manual_reference' => 'HF-AUD-LINK-1', 'source' => 'manual',
            'head_name' => 'Linked Family', 'status' => 'active', 'registered_at' => now(),
        ]);
        $member = FamilyMember::query()->create([
            'family_id' => $family->id, 'name' => 'Linked Member', 'gender' => 'male', 'age' => 35,
            'is_head' => true, 'status' => 'active',
        ]);
        Karyakar::query()->create([
            'center_id' => $center->id, 'family_id' => $family->id, 'family_member_id' => $member->id,
            'karyakar_reference' => 'SK-AUD-LINK-1', 'source' => 'family_nomination', 'full_name' => 'Linked Member',
            'gender' => 'male', 'age' => 35, 'category' => 'Yuvak Karyakar', 'status' => 'approved', 'approved_at' => now(),
        ]);

        $this->actingAs($admin)->put("/registration/families/{$family->id}", [
            'head_name' => $family->head_name, 'status' => 'active', 'change_reason' => 'Change age',
            'members' => [[
                'id' => $member->id, 'name' => $member->name, 'gender' => 'male', 'age' => 24,
                'mobile' => null, 'relationship' => null, 'is_head' => true, 'status' => 'active',
            ]],
        ])->assertSessionHasErrors('members');
        $this->assertSame(35, $member->fresh()->age);
    }

    public function test_correction_request_is_scoped_and_requires_review_note_for_final_decision(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'correction-admin@example.test', $zone, $center);
        $foreign = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Foreign Correction Center', 'code' => 'FCC', 'status' => 'active']);
        $foreignAdmin = $this->user('center_admin', 'foreign-correction@example.test', $zone, $foreign);

        $this->actingAs($admin)->post('/support/corrections', [
            'center_id' => $center->id, 'module' => 'family', 'record_reference' => 'HF-AUD-1',
            'requested_change' => 'Correct head mobile.', 'reason' => 'Verified data correction.',
        ])->assertRedirect();
        $correction = CorrectionRequest::query()->firstOrFail();
        $this->assertSame('pending', $correction->status);

        $this->actingAs($admin)->put("/support/corrections/{$correction->id}", [
            'status' => 'approved', 'review_note' => '',
        ])->assertSessionHasErrors('review_note');
        $this->actingAs($admin)->put("/support/corrections/{$correction->id}", [
            'status' => 'approved', 'review_note' => 'Approved after verification.',
        ])->assertRedirect();
        $this->assertSame('approved', $correction->fresh()->status);

        $this->actingAs($foreignAdmin)->put("/support/corrections/{$correction->id}", [
            'status' => 'completed', 'review_note' => 'Unauthorized completion.',
        ])->assertForbidden();
    }

    public function test_center_admin_does_not_see_another_users_global_correction_request(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'correction-privacy@example.test', $zone, $center);
        $super = $this->user('super_admin', 'correction-super@example.test', $zone);
        CorrectionRequest::query()->create([
            'user_id' => $super->id, 'center_id' => null, 'module' => 'other', 'requested_change' => 'Global private correction',
            'reason' => 'Organization-level reason', 'status' => 'pending',
        ]);

        $this->actingAs($admin)->get('/support/corrections')->assertOk()->assertInertia(fn ($page) => $page->has('requests', 0));
    }


    public function test_group_area_change_syncs_current_operational_targets_but_preserves_expired_history(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'group-area-sync@example.test', $zone, $center);
        $oldArea = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Old Target Area', 'status' => 'active']);
        $oldSociety = Society::query()->create(['center_id' => $center->id, 'sampark_area_id' => $oldArea->id, 'name' => 'Old Target Society', 'status' => 'active']);
        $newArea = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'New Target Area', 'status' => 'active']);
        $newSociety = Society::query()->create(['center_id' => $center->id, 'sampark_area_id' => $newArea->id, 'name' => 'New Target Society', 'status' => 'active']);
        $group = SankalpGroup::query()->create([
            'center_id' => $center->id, 'group_code' => 'AUD-AREA-SYNC', 'group_type' => 'two_male',
            'sampark_area_id' => $oldArea->id, 'society_id' => $oldSociety->id, 'status' => 'active', 'activated_at' => now(),
        ]);
        $current = Target::query()->create([
            'center_id' => $center->id, 'group_id' => $group->id, 'sampark_area_id' => $oldArea->id, 'society_id' => $oldSociety->id,
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDays(7)->toDateString(),
            'target_quantity' => 10, 'completed_quantity' => 2, 'status' => 'active', 'assigned_by' => $admin->id,
        ]);
        $expired = Target::query()->create([
            'center_id' => $center->id, 'group_id' => $group->id, 'sampark_area_id' => $oldArea->id, 'society_id' => $oldSociety->id,
            'start_date' => now()->subDays(20)->toDateString(), 'end_date' => now()->subDay()->toDateString(),
            'target_quantity' => 10, 'completed_quantity' => 10, 'status' => 'completed', 'assigned_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post('/assignments/areas', [
            'record_type' => 'group', 'record_id' => $group->id, 'sampark_area_id' => $newArea->id,
            'society_id' => $newSociety->id, 'reason' => 'Group operational area changed',
        ])->assertRedirect();

        $this->assertSame($newArea->id, $group->fresh()->sampark_area_id);
        $this->assertSame($newArea->id, $current->fresh()->sampark_area_id);
        $this->assertSame($newSociety->id, $current->fresh()->society_id);
        $this->assertSame($oldArea->id, $expired->fresh()->sampark_area_id);
        $this->assertTrue(AuditLog::query()
            ->where('module', 'target')
            ->where('action', 'target_scope_synced_to_group')
            ->where('record_id', (string) $current->id)
            ->where('reason', 'Group operational area changed')
            ->exists());
    }


    public function test_inactive_family_or_member_cannot_be_nominated_as_karyakar(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'inactive-nomination@example.test', $zone, $center);

        $inactiveFamily = Family::query()->create([
            'center_id' => $center->id, 'manual_reference' => 'HF-AUD-INACTIVE-FAMILY', 'source' => 'manual',
            'head_name' => 'Inactive Family', 'status' => 'inactive', 'registered_at' => now(),
        ]);
        $activeMemberInInactiveFamily = FamilyMember::query()->create([
            'family_id' => $inactiveFamily->id, 'name' => 'Active Member', 'gender' => 'male', 'age' => 30, 'status' => 'active',
        ]);

        $this->actingAs($admin)->post('/registration/karyakars/nominate', [
            'family_member_id' => $activeMemberInInactiveFamily->id,
        ])->assertStatus(422);
        $this->assertDatabaseMissing('karyakars', ['family_member_id' => $activeMemberInInactiveFamily->id]);

        $activeFamily = Family::query()->create([
            'center_id' => $center->id, 'manual_reference' => 'HF-AUD-INACTIVE-MEMBER', 'source' => 'manual',
            'head_name' => 'Active Family', 'status' => 'active', 'registered_at' => now(),
        ]);
        $inactiveMember = FamilyMember::query()->create([
            'family_id' => $activeFamily->id, 'name' => 'Inactive Member', 'gender' => 'female', 'age' => 30, 'status' => 'inactive',
        ]);

        $this->actingAs($admin)->post('/registration/karyakars/nominate', [
            'family_member_id' => $inactiveMember->id,
        ])->assertStatus(422);
        $this->assertDatabaseMissing('karyakars', ['family_member_id' => $inactiveMember->id]);
    }

    public function test_manual_family_rejects_more_than_one_head_member(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'multi-head-family@example.test', $zone, $center);

        $this->actingAs($admin)->post('/registration/families', [
            'center_id' => $center->id,
            'head_name' => 'Multiple Head Family',
            'members' => [
                ['name' => 'Head One', 'gender' => 'male', 'age' => 40, 'is_head' => true],
                ['name' => 'Head Two', 'gender' => 'female', 'age' => 38, 'is_head' => true],
            ],
        ])->assertSessionHasErrors('members');

        $this->assertDatabaseMissing('families', ['center_id' => $center->id, 'head_name' => 'Multiple Head Family']);
    }

    public function test_bal_group_rejects_child_from_inactive_family(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'bal-inactive-child-admin@example.test', $zone, $center);
        $sanchalakUser = $this->user('sanchalak', 'bal-sanchalak-active@example.test', $zone, $center);
        $sanchalak = $this->approvedKaryakar($center, 'SK-AUD-BAL-SANCHALAK', $sanchalakUser);

        $children = collect();
        for ($i = 1; $i <= 3; $i++) {
            $family = Family::query()->create([
                'center_id' => $center->id,
                'manual_reference' => "HF-AUD-BAL-{$i}",
                'source' => 'manual',
                'head_name' => "Bal Family {$i}",
                'status' => $i === 3 ? 'inactive' : 'active',
                'registered_at' => now(),
            ]);
            $children->push(FamilyMember::query()->create([
                'family_id' => $family->id,
                'name' => "Child {$i}",
                'gender' => $i === 2 ? 'female' : 'male',
                'age' => 10,
                'status' => 'active',
            ]));
        }

        $this->actingAs($admin)->post('/bal-pravruti/groups', [
            'center_id' => $center->id,
            'sanchalak_karyakar_id' => $sanchalak->id,
            'child_member_ids' => $children->pluck('id')->all(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('bal_groups', 0);
    }


    public function test_family_transfer_from_active_group_closes_open_targets_when_group_returns_to_draft(): void
    {
        [$zone, $center] = $this->setupOrg();
        $admin = $this->user('center_admin', 'transfer-target-close@example.test', $zone, $center);
        $a = $this->approvedKaryakar($center, 'SK-AUD-TC-A');
        $b = $this->approvedKaryakar($center, 'SK-AUD-TC-B');
        $c = $this->approvedKaryakar($center, 'SK-AUD-TC-C');
        $area = \App\Models\SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Transfer Target Area', 'status' => 'active']);

        $source = \App\Models\SankalpGroup::query()->create([
            'center_id' => $center->id, 'group_code' => 'AUD-TC-SRC', 'group_type' => 'two_male',
            'sampark_area_id' => $area->id, 'status' => 'active', 'activated_at' => now(), 'created_by' => $admin->id,
        ]);
        $destination = \App\Models\SankalpGroup::query()->create([
            'center_id' => $center->id, 'group_code' => 'AUD-TC-DST', 'group_type' => 'two_male',
            'sampark_area_id' => $area->id, 'status' => 'draft', 'created_by' => $admin->id,
        ]);
        foreach ([[$a,1],[$b,2]] as [$k,$position]) {
            \App\Models\GroupKaryakar::query()->create(['group_id' => $source->id, 'karyakar_id' => $k->id, 'position' => $position, 'status' => 'active', 'assigned_at' => now()]);
        }
        foreach ([[$a,1],[$c,2]] as [$k,$position]) {
            \App\Models\GroupKaryakar::query()->create(['group_id' => $destination->id, 'karyakar_id' => $k->id, 'position' => $position, 'status' => 'active', 'assigned_at' => now()]);
        }
        $family = \App\Models\Family::query()->create([
            'center_id' => $center->id, 'manual_reference' => 'HF-AUD-TC', 'source' => 'manual',
            'head_name' => 'Transfer Target Family', 'status' => 'active', 'registered_at' => now(),
        ]);
        $assignment = \App\Models\GroupFamilyAssignment::query()->create([
            'group_id' => $source->id, 'family_id' => $family->id, 'slot_number' => 1,
            'assignment_type' => 'fixed', 'assignment_source' => 'admin', 'status' => 'active', 'assigned_at' => now(),
        ]);
        $target = \App\Models\Target::query()->create([
            'center_id' => $center->id, 'group_id' => $source->id, 'sampark_area_id' => $area->id,
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDays(7)->toDateString(),
            'target_quantity' => 10, 'completed_quantity' => 0, 'status' => 'active', 'assigned_by' => $admin->id,
        ]);

        app(\App\Services\Assignments\GroupAssignmentService::class)
            ->transferFamily($assignment, $destination, 'remaining', $admin, 'Family moved; source target must stop');

        $this->assertSame('draft', $source->fresh()->status);
        $this->assertSame('closed', $target->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'target_closed_after_family_transfer',
            'record_id' => (string) $target->id,
            'reason' => 'Family moved; source target must stop',
        ]);
    }

}
