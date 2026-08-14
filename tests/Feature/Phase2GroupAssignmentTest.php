<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Center;
use App\Models\Family;
use App\Models\GroupFamilyAssignment;
use App\Models\Karyakar;
use App\Models\RemainingFamilyReport;
use App\Models\Role;
use App\Models\SamparkArea;
use App\Models\SankalpGroup;
use App\Models\Society;
use App\Models\Target;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2GroupAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function centerAdmin(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $zone = Zone::query()->create(['name' => 'North', 'code' => 'N', 'status' => 'active']);
        $center = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Gandhinagar', 'code' => 'GND', 'status' => 'active']);
        $role = Role::query()->where('slug', 'center_admin')->firstOrFail();
        $user = User::query()->create(['name' => 'Center Admin', 'email' => 'phase2@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $user->roles()->attach($role->id, ['zone_id' => $zone->id, 'center_id' => $center->id, 'is_primary' => true]);
        return [$user, $center, $zone];
    }

    private function approvedKaryakar(Center $center, string $name, string $gender = 'male', ?User $user = null): Karyakar
    {
        static $n = 1;
        return Karyakar::query()->create([
            'center_id' => $center->id,
            'user_id' => $user?->id,
            'karyakar_reference' => sprintf('SK-%s-%06d', $center->code, $n++),
            'source' => 'manual',
            'full_name' => $name,
            'gender' => $gender,
            'age' => 35,
            'category' => $gender === 'male' ? 'Yuvak Karyakar' : 'Yuvti Karyakar',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    private function family(Center $center, int $number): Family
    {
        return Family::query()->create([
            'center_id' => $center->id,
            'manual_reference' => sprintf('HF-%s-%06d', $center->code, $number),
            'source' => 'manual',
            'head_name' => "Family {$number}",
            'status' => 'active',
        ]);
    }

    private function createGroup(User $admin, Center $center, Karyakar $one, Karyakar $two, string $type = 'two_male'): SankalpGroup
    {
        $this->actingAs($admin)->post('/assignments/groups', [
            'center_id' => $center->id,
            'group_type' => $type,
            'karyakar_ids' => [$one->id, $two->id],
        ])->assertRedirect();
        return SankalpGroup::query()->latest('id')->firstOrFail();
    }

    public function test_group_requires_exactly_two_approved_karyakars_and_center_code_numbering(): void
    {
        [$admin, $center] = $this->centerAdmin();
        $one = $this->approvedKaryakar($center, 'A Patel');
        $two = $this->approvedKaryakar($center, 'B Patel');
        $group = $this->createGroup($admin, $center, $one, $two);

        $this->assertSame('GND-001', $group->group_code);
        $this->assertSame('draft', $group->status);
        $this->assertSame(2, $group->karyakarAssignments()->where('status', 'active')->count());

        $pending = Karyakar::query()->create([
            'center_id' => $center->id, 'karyakar_reference' => 'SK-GND-PENDING', 'source' => 'manual', 'full_name' => 'Pending',
            'gender' => 'male', 'age' => 35, 'category' => 'Yuvak Karyakar', 'status' => 'pending',
        ]);
        $this->actingAs($admin)->post('/assignments/groups', [
            'center_id' => $center->id, 'group_type' => 'two_male', 'karyakar_ids' => [$one->id, $pending->id],
        ])->assertSessionHasErrors('karyakar_ids');
    }

    public function test_group_combination_is_validated_and_karyakar_can_belong_to_multiple_groups(): void
    {
        [$admin, $center] = $this->centerAdmin();
        $maleOne = $this->approvedKaryakar($center, 'Male One', 'male');
        $maleTwo = $this->approvedKaryakar($center, 'Male Two', 'male');
        $female = $this->approvedKaryakar($center, 'Female One', 'female');

        $this->actingAs($admin)->post('/assignments/groups', [
            'center_id' => $center->id, 'group_type' => 'couple', 'karyakar_ids' => [$maleOne->id, $maleTwo->id],
        ])->assertSessionHasErrors('group_type');

        $first = $this->createGroup($admin, $center, $maleOne, $maleTwo, 'two_male');
        $second = $this->createGroup($admin, $center, $maleOne, $female, 'couple');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $maleOne->groups()->count());
        $this->assertSame('GND-002', $second->group_code);
    }

    public function test_group_activation_enforces_exactly_ten_families_with_fixed_remaining_ratio(): void
    {
        [$admin, $center] = $this->centerAdmin();
        $one = $this->approvedKaryakar($center, 'A');
        $two = $this->approvedKaryakar($center, 'B');
        $group = $this->createGroup($admin, $center, $one, $two);

        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($admin)->post("/assignments/groups/{$group->id}/families", ['family_id' => $this->family($center, $i)->id, 'assignment_type' => 'fixed'])->assertRedirect();
        }
        for ($i = 7; $i <= 10; $i++) {
            $this->actingAs($admin)->post("/assignments/groups/{$group->id}/families", ['family_id' => $this->family($center, $i)->id, 'assignment_type' => 'remaining'])->assertRedirect();
        }

        $this->actingAs($admin)->post("/assignments/groups/{$group->id}/activate")->assertRedirect();
        $group->refresh();
        $this->assertSame('active', $group->status);
        $this->assertSame(10, $group->familyAssignments()->where('status', 'active')->count());
        $this->assertSame(6, $group->familyAssignments()->where('status', 'active')->where('assignment_type', 'fixed')->count());
        $this->assertSame(4, $group->familyAssignments()->where('status', 'active')->where('assignment_type', 'remaining')->count());
    }

    public function test_duplicate_active_family_assignment_is_prevented_and_transfer_closes_old_assignment(): void
    {
        [$admin, $center] = $this->centerAdmin();
        $k1 = $this->approvedKaryakar($center, 'A'); $k2 = $this->approvedKaryakar($center, 'B'); $k3 = $this->approvedKaryakar($center, 'C');
        $groupOne = $this->createGroup($admin, $center, $k1, $k2);
        $groupTwo = $this->createGroup($admin, $center, $k1, $k3);
        $family = $this->family($center, 50);

        $this->actingAs($admin)->post("/assignments/groups/{$groupOne->id}/families", ['family_id' => $family->id, 'assignment_type' => 'fixed'])->assertRedirect();
        $this->actingAs($admin)->post("/assignments/groups/{$groupTwo->id}/families", ['family_id' => $family->id, 'assignment_type' => 'remaining'])->assertSessionHasErrors('family_id');

        $old = GroupFamilyAssignment::query()->where('group_id', $groupOne->id)->where('family_id', $family->id)->firstOrFail();
        $this->actingAs($admin)->post("/assignments/groups/{$groupOne->id}/families/{$old->id}/transfer", [
            'destination_group_id' => $groupTwo->id,
            'assignment_type' => 'remaining',
            'reason' => 'Family moved to another Society',
        ])->assertRedirect();

        $this->assertSame('transferred', $old->fresh()->status);
        $new = GroupFamilyAssignment::query()->where('family_id', $family->id)->where('status', 'active')->firstOrFail();
        $this->assertSame($groupTwo->id, $new->group_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'family_transferred', 'reason' => 'Family moved to another Society']);
    }

    public function test_linked_karyakar_can_select_existing_remaining_family_and_report_new_family(): void
    {
        [$admin, $center, $zone] = $this->centerAdmin();
        $role = Role::query()->where('slug', 'karyakar')->firstOrFail();
        $fieldUser = User::query()->create(['name' => 'Field Karyakar', 'email' => 'field@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $fieldUser->roles()->attach($role->id, ['zone_id' => $zone->id, 'center_id' => $center->id, 'is_primary' => true]);
        $field = $this->approvedKaryakar($center, 'Field Person', 'male', $fieldUser);
        $partner = $this->approvedKaryakar($center, 'Partner', 'male');
        $group = $this->createGroup($admin, $center, $field, $partner);
        $area = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Sector 8', 'status' => 'active']);
        $society = Society::query()->create(['center_id' => $center->id, 'sampark_area_id' => $area->id, 'name' => 'Field Society', 'status' => 'active']);
        $this->actingAs($admin)->post('/assignments/areas', ['record_type' => 'group', 'record_id' => $group->id, 'sampark_area_id' => $area->id, 'society_id' => $society->id, 'reason' => 'Field planning'])->assertRedirect();
        $existing = $this->family($center, 80);
        $existing->update(['sampark_area_id' => $area->id, 'society_id' => $society->id]);

        $this->actingAs($fieldUser)->post("/assignments/groups/{$group->id}/remaining-family", ['family_id' => $existing->id, 'change_note' => 'Selected during field planning'])->assertRedirect();
        $this->assertDatabaseHas('group_family_assignments', ['group_id' => $group->id, 'family_id' => $existing->id, 'assignment_type' => 'remaining', 'assignment_source' => 'karyakar', 'status' => 'active']);

        $this->actingAs($fieldUser)->post("/assignments/groups/{$group->id}/remaining-family/report", [
            'head_name' => 'New Shah Family', 'head_mobile' => '9999999999', 'city_village' => 'Gandhinagar', 'note' => 'Please verify',
        ])->assertRedirect();
        $report = RemainingFamilyReport::query()->firstOrFail();
        $this->assertSame('pending', $report->status);
        $this->assertSame('pending_verification', $report->family->status);
        $this->assertSame('karyakar_reported', $report->family->source);

        $this->actingAs($admin)->post("/assignments/groups/{$group->id}/remaining-family-reports/{$report->id}/review", ['decision' => 'accepted', 'review_note' => 'Verified by Center Admin'])->assertRedirect();
        $this->assertSame('accepted', $report->fresh()->status);
        $this->assertDatabaseHas('group_family_assignments', ['group_id' => $group->id, 'family_id' => $report->family_id, 'assignment_type' => 'remaining', 'status' => 'active']);
    }

    public function test_area_society_assignment_and_target_creation_are_center_scoped(): void
    {
        [$admin, $center] = $this->centerAdmin();
        $k1 = $this->approvedKaryakar($center, 'A'); $k2 = $this->approvedKaryakar($center, 'B');
        $group = $this->createGroup($admin, $center, $k1, $k2);
        $area = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Sector 5', 'status' => 'active']);
        $society = Society::query()->create(['center_id' => $center->id, 'sampark_area_id' => $area->id, 'name' => 'Akshar Society', 'status' => 'active']);

        $this->actingAs($admin)->post('/assignments/areas', [
            'record_type' => 'group', 'record_id' => $group->id, 'sampark_area_id' => $area->id, 'society_id' => $society->id, 'reason' => 'Initial assignment',
        ])->assertRedirect();
        $this->assertSame($area->id, $group->fresh()->sampark_area_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'assignment_changed', 'reason' => 'Initial assignment']);

        for ($i = 300; $i <= 304; $i++) {
            $this->actingAs($admin)->post("/assignments/groups/{$group->id}/families", ['family_id' => $this->family($center, $i)->id, 'assignment_type' => 'fixed'])->assertRedirect();
        }
        for ($i = 305; $i <= 309; $i++) {
            $this->actingAs($admin)->post("/assignments/groups/{$group->id}/families", ['family_id' => $this->family($center, $i)->id, 'assignment_type' => 'remaining'])->assertRedirect();
        }
        $this->actingAs($admin)->post("/assignments/groups/{$group->id}/activate")->assertRedirect();
        $this->assertSame('active', $group->fresh()->status);

        $this->actingAs($admin)->post('/assignments/targets', [
            'center_id' => $center->id, 'group_id' => $group->id, 'karyakar_id' => $k1->id,
            'sampark_area_id' => $area->id, 'society_id' => $society->id, 'name' => 'May Target',
            'start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'target_quantity' => 10,
        ])->assertRedirect();
        $target = Target::query()->firstOrFail();
        $this->assertSame(10, $target->target_quantity);
        $this->assertSame(0, $target->completed_quantity);
        $this->assertSame($group->id, $target->group_id);
    }

    public function test_karyakar_cannot_assign_fixed_family(): void
    {
        [$admin, $center, $zone] = $this->centerAdmin();
        $role = Role::query()->where('slug', 'karyakar')->firstOrFail();
        $fieldUser = User::query()->create(['name' => 'Field', 'email' => 'no-fixed@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $fieldUser->roles()->attach($role->id, ['zone_id' => $zone->id, 'center_id' => $center->id, 'is_primary' => true]);
        $field = $this->approvedKaryakar($center, 'Field', 'male', $fieldUser);
        $partner = $this->approvedKaryakar($center, 'Partner', 'male');
        $group = $this->createGroup($admin, $center, $field, $partner);
        $family = $this->family($center, 99);

        $this->actingAs($fieldUser)->post("/assignments/groups/{$group->id}/families", ['family_id' => $family->id, 'assignment_type' => 'fixed'])->assertForbidden();
        $this->assertDatabaseMissing('group_family_assignments', ['family_id' => $family->id, 'status' => 'active']);
    }
}
