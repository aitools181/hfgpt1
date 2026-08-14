<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\Family;
use App\Models\GroupFamilyAssignment;
use App\Models\HomeVisit;
use App\Models\InactivityEvent;
use App\Models\Karyakar;
use App\Models\KaryakarBadge;
use App\Models\Role;
use App\Models\SamparkArea;
use App\Models\SankalpGroup;
use App\Models\Target;
use App\Models\User;
use App\Models\Zone;
use App\Services\Field\InactivityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase3FieldExecutionTest extends TestCase
{
    use RefreshDatabase;

    private int $familySequence = 1;
    private int $karyakarSequence = 1;
    private int $groupSequence = 1;

    private function context(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $zone = Zone::query()->create(['name' => 'North Zone', 'code' => 'NZ', 'status' => 'active']);
        $center = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Gandhinagar', 'code' => 'GND', 'status' => 'active']);
        $area = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Sector 5', 'status' => 'active']);

        $centerRole = Role::query()->where('slug', 'center_admin')->firstOrFail();
        $admin = User::query()->create(['name' => 'Center Admin', 'email' => 'phase3-admin@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $admin->roles()->attach($centerRole->id, ['zone_id' => $zone->id, 'center_id' => $center->id, 'is_primary' => true]);

        return [$zone, $center, $area, $admin];
    }

    private function fieldUser(Zone $zone, Center $center, string $name = 'Field Karyakar'): array
    {
        $role = Role::query()->where('slug', 'karyakar')->firstOrFail();
        $email = strtolower(str_replace(' ', '-', $name)).'-'.$this->karyakarSequence.'@example.test';
        $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => 'StrongPassword123!', 'status' => 'active']);
        $user->roles()->attach($role->id, ['zone_id' => $zone->id, 'center_id' => $center->id, 'is_primary' => true]);

        $karyakar = Karyakar::query()->create([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'karyakar_reference' => sprintf('SK-GND-%06d', $this->karyakarSequence++),
            'source' => 'manual',
            'full_name' => $name,
            'gender' => 'male',
            'age' => 35,
            'category' => 'Yuvak Karyakar',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        return [$user, $karyakar];
    }

    private function approvedPartner(Center $center, string $name = 'Partner'): Karyakar
    {
        return Karyakar::query()->create([
            'center_id' => $center->id,
            'karyakar_reference' => sprintf('SK-GND-%06d', $this->karyakarSequence++),
            'source' => 'manual',
            'full_name' => $name,
            'gender' => 'male',
            'age' => 35,
            'category' => 'Yuvak Karyakar',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    private function activeGroup(Center $center, SamparkArea $area, Karyakar $one, Karyakar $two, int $familyCount = 10): array
    {
        $group = SankalpGroup::query()->create([
            'center_id' => $center->id,
            'sampark_area_id' => $area->id,
            'group_code' => sprintf('GND-%03d', $this->groupSequence++),
            'group_type' => 'two_male',
            'status' => 'active',
            'activated_at' => now()->subDays(8),
        ]);
        $group->karyakarAssignments()->create(['karyakar_id' => $one->id, 'position' => 1, 'status' => 'active', 'assigned_at' => now()->subDays(8)]);
        $group->karyakarAssignments()->create(['karyakar_id' => $two->id, 'position' => 2, 'status' => 'active', 'assigned_at' => now()->subDays(8)]);

        $assignments = collect();
        for ($slot = 1; $slot <= $familyCount; $slot++) {
            $family = Family::query()->create([
                'center_id' => $center->id,
                'sampark_area_id' => $area->id,
                'manual_reference' => sprintf('HF-GND-%06d', $this->familySequence++),
                'source' => 'manual',
                'head_name' => "Family {$this->familySequence}",
                'head_mobile' => '999990'.str_pad((string) $this->familySequence, 4, '0', STR_PAD_LEFT),
                'status' => 'active',
            ]);
            $assignments->push($group->familyAssignments()->create([
                'family_id' => $family->id,
                'slot_number' => $slot,
                'assignment_type' => $slot <= 6 ? 'fixed' : 'remaining',
                'assignment_source' => 'admin',
                'status' => 'active',
                'assigned_at' => now()->subDays(8),
            ]));
        }

        return [$group, $assignments];
    }

    private function target(Center $center, SamparkArea $area, SankalpGroup $group, ?Karyakar $karyakar = null, int $quantity = 10): Target
    {
        return Target::query()->create([
            'center_id' => $center->id,
            'group_id' => $group->id,
            'karyakar_id' => $karyakar?->id,
            'sampark_area_id' => $area->id,
            'name' => $karyakar ? 'Individual Target' : 'Group Target',
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->addDays(20)->toDateString(),
            'target_quantity' => $quantity,
            'completed_quantity' => 0,
            'status' => 'active',
        ]);
    }

    public function test_assigned_karyakar_can_complete_home_visit_and_target_progress_updates_once(): void
    {
        [$zone, $center, $area] = $this->context();
        [$fieldUser, $field] = $this->fieldUser($zone, $center);
        $partner = $this->approvedPartner($center);
        [$group, $assignments] = $this->activeGroup($center, $area, $field, $partner);
        $groupTarget = $this->target($center, $area, $group, null, 10);
        $individualTarget = $this->target($center, $area, $group, $field, 5);
        $assignment = $assignments->first();

        $response = $this->actingAs($fieldUser)->post("/field/home-visits/{$assignment->id}", [
            'target_id' => $individualTarget->id,
            'completion_note' => 'Happy Family message delivered.',
        ]);

        $response->assertRedirect()->assertSessionHas('completion_report');
        $this->assertDatabaseHas('home_visits', [
            'group_family_assignment_id' => $assignment->id,
            'family_id' => $assignment->family_id,
            'karyakar_id' => $field->id,
            'message_delivered' => 1,
            'is_admin_override' => 0,
        ]);
        $this->assertSame(1, $groupTarget->fresh()->completed_quantity);
        $this->assertSame(1, $individualTarget->fresh()->completed_quantity);

        $this->actingAs($fieldUser)->post("/field/home-visits/{$assignment->id}", ['target_id' => $individualTarget->id])
            ->assertSessionHasErrors('family');
        $this->assertSame(1, HomeVisit::query()->where('group_family_assignment_id', $assignment->id)->count());
    }

    public function test_unassigned_karyakar_cannot_complete_another_groups_family(): void
    {
        [$zone, $center, $area] = $this->context();
        [$fieldUser, $field] = $this->fieldUser($zone, $center, 'Assigned Field');
        [$otherUser] = $this->fieldUser($zone, $center, 'Other Field');
        $partner = $this->approvedPartner($center);
        [, $assignments] = $this->activeGroup($center, $area, $field, $partner);

        $this->actingAs($otherUser)->post('/field/home-visits/'.$assignments->first()->id)
            ->assertSessionHasErrors('authorization');
        $this->assertDatabaseCount('home_visits', 0);
    }

    public function test_badges_are_awarded_at_3_6_9_12_and_15_completed_families(): void
    {
        [$zone, $center, $area] = $this->context();
        [$fieldUser, $field] = $this->fieldUser($zone, $center);
        $partnerOne = $this->approvedPartner($center, 'Partner One');
        $partnerTwo = $this->approvedPartner($center, 'Partner Two');
        [, $firstAssignments] = $this->activeGroup($center, $area, $field, $partnerOne);
        [, $secondAssignments] = $this->activeGroup($center, $area, $field, $partnerTwo);

        $all = $firstAssignments->concat($secondAssignments)->take(15);
        foreach ($all as $assignment) {
            $this->actingAs($fieldUser)->post('/field/home-visits/'.$assignment->id)->assertRedirect();
        }

        $this->assertSame([3, 6, 9, 12, 15], KaryakarBadge::query()->where('karyakar_id', $field->id)->orderBy('milestone')->pluck('milestone')->all());
        $this->assertSame(15, HomeVisit::query()->where('karyakar_id', $field->id)->count());
    }

    public function test_four_day_reminder_and_seven_day_alert_are_created_without_duplicates(): void
    {
        [$zone, $center, $area] = $this->context();
        [, $field] = $this->fieldUser($zone, $center);
        $partner = $this->approvedPartner($center);
        [$group] = $this->activeGroup($center, $area, $field, $partner);
        $group->update(['activated_at' => now()->subDays(8)]);

        $service = app(InactivityService::class);
        $anchor = $group->activated_at->copy();
        $service->checkGroupKaryakar($group->fresh(), $field, $anchor->copy()->addDays(4));
        $service->checkGroupKaryakar($group->fresh(), $field, $anchor->copy()->addDays(4)->addHour());
        $this->assertSame(1, InactivityEvent::query()->where('event_type', 'reminder')->count());

        $service->checkGroupKaryakar($group->fresh(), $field, $anchor->copy()->addDays(7));
        $this->assertSame(1, InactivityEvent::query()->where('event_type', 'alert')->count());
        $this->assertSame('escalated', InactivityEvent::query()->where('event_type', 'reminder')->firstOrFail()->status);
    }

    public function test_new_home_visit_resolves_open_reminder_and_alert_history(): void
    {
        [$zone, $center, $area] = $this->context();
        [$fieldUser, $field] = $this->fieldUser($zone, $center);
        $partner = $this->approvedPartner($center);
        [$group, $assignments] = $this->activeGroup($center, $area, $field, $partner);
        $group->update(['activated_at' => now()->subDays(8)]);
        $service = app(InactivityService::class);
        $service->checkGroupKaryakar($group->fresh(), $field, now());
        $this->assertSame(2, InactivityEvent::query()->whereIn('status', ['open', 'escalated'])->count());

        $this->actingAs($fieldUser)->post('/field/home-visits/'.$assignments->first()->id)->assertRedirect();
        $this->assertSame(0, InactivityEvent::query()->whereIn('status', ['open', 'escalated'])->count());
        $this->assertSame(2, InactivityEvent::query()->where('status', 'resolved')->count());
    }

    public function test_super_admin_override_requires_assigned_karyakar_and_reason(): void
    {
        [$zone, $center, $area] = $this->context();
        [, $field] = $this->fieldUser($zone, $center);
        $partner = $this->approvedPartner($center);
        [, $assignments] = $this->activeGroup($center, $area, $field, $partner);
        $superRole = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $super = User::query()->create(['name' => 'Super Admin', 'email' => 'super-phase3@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $super->roles()->attach($superRole->id, ['is_primary' => true]);
        $assignment = $assignments->first();

        $this->actingAs($super)->post('/field/home-visits/'.$assignment->id, ['karyakar_id' => $field->id])
            ->assertSessionHasErrors('override_reason');

        $this->actingAs($super)->post('/field/home-visits/'.$assignment->id, [
            'karyakar_id' => $field->id,
            'override_reason' => 'Authorized correction after field confirmation.',
        ])->assertRedirect();

        $visit = HomeVisit::query()->firstOrFail();
        $this->assertTrue($visit->is_admin_override);
        $this->assertSame('Authorized correction after field confirmation.', $visit->override_reason);
    }
}
