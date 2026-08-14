<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\BalCompletionReport;
use App\Models\BalGroup;
use App\Models\BalGroupChild;
use App\Models\Center;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\GroupFamilyAssignment;
use App\Models\GroupKaryakar;
use App\Models\HomeVisit;
use App\Models\InventoryItem;
use App\Models\Karyakar;
use App\Models\Role;
use App\Models\SamparkArea;
use App\Models\SankalpGroup;
use App\Models\SharedContent;
use App\Models\Society;
use App\Models\Target;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PilotDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! filter_var(env('PILOT_DATA', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $password = (string) env('PILOT_PASSWORD', '');
        if (mb_strlen($password) < 16) {
            throw new \RuntimeException('PILOT_PASSWORD must be at least 16 characters when PILOT_DATA=true.');
        }
        $zone = Zone::query()->updateOrCreate(['code' => 'PLT-Z'], ['name' => 'Pilot Zone', 'status' => 'active']);
        $center = Center::query()->updateOrCreate(['code' => 'PLT'], ['zone_id' => $zone->id, 'name' => 'Pilot Center', 'city' => 'Gandhinagar', 'status' => 'active']);
        $area = SamparkArea::query()->updateOrCreate(['center_id' => $center->id, 'name' => 'Pilot Area'], ['external_code' => 'PLT-A1', 'city_village' => 'Gandhinagar', 'status' => 'active']);
        $society = Society::query()->updateOrCreate(['center_id' => $center->id, 'name' => 'Pilot Society'], ['sampark_area_id' => $area->id, 'external_code' => 'PLT-S1', 'status' => 'active']);

        $centerAdmin = $this->roleUser('center_admin', 'Pilot Center Admin', 'pilot.center@example.test', $password, $zone, $center);
        $fieldOne = $this->roleUser('karyakar', 'Pilot Karyakar One', 'pilot.karyakar1@example.test', $password, $zone, $center);
        $fieldTwo = $this->roleUser('karyakar', 'Pilot Karyakar Two', 'pilot.karyakar2@example.test', $password, $zone, $center);
        $sanchalakUser = $this->roleUser('sanchalak', 'Pilot Sanchalak', 'pilot.sanchalak@example.test', $password, $zone, $center);

        $families = collect(range(1, 12))->map(function (int $i) use ($center, $area, $society, $centerAdmin): Family {
            $family = Family::query()->updateOrCreate(
                ['manual_reference' => sprintf('HF-PLT-%06d', $i)],
                ['center_id' => $center->id, 'sampark_area_id' => $area->id, 'society_id' => $society->id, 'source' => 'manual', 'head_name' => "Pilot Family {$i}", 'head_mobile' => '900000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'city_village' => 'Gandhinagar', 'status' => 'active', 'registered_at' => now(), 'registered_by' => $centerAdmin->id]
            );
            FamilyMember::query()->updateOrCreate(['family_id' => $family->id, 'external_member_id' => "PLT-M{$i}-H"], ['name' => "Head {$i}", 'gender' => $i % 2 ? 'male' : 'female', 'age' => 36, 'relationship' => 'Head', 'is_head' => true, 'status' => 'active']);
            FamilyMember::query()->updateOrCreate(['family_id' => $family->id, 'external_member_id' => "PLT-M{$i}-C"], ['name' => "Child {$i}", 'gender' => $i % 2 ? 'female' : 'male', 'age' => 8 + ($i % 3), 'relationship' => 'Child', 'status' => 'active']);
            return $family;
        });

        $k1 = Karyakar::query()->updateOrCreate(['karyakar_reference' => 'SK-PLT-000001'], ['center_id' => $center->id, 'user_id' => $fieldOne->id, 'sampark_area_id' => $area->id, 'society_id' => $society->id, 'source' => 'manual', 'full_name' => 'Pilot Karyakar One', 'gender' => 'male', 'age' => 35, 'category' => 'Yuvak Karyakar', 'mobile' => '9111111111', 'status' => 'approved', 'approved_by' => $centerAdmin->id, 'approved_at' => now()]);
        $k2 = Karyakar::query()->updateOrCreate(['karyakar_reference' => 'SK-PLT-000002'], ['center_id' => $center->id, 'user_id' => $fieldTwo->id, 'sampark_area_id' => $area->id, 'society_id' => $society->id, 'source' => 'manual', 'full_name' => 'Pilot Karyakar Two', 'gender' => 'male', 'age' => 32, 'category' => 'Yuvak Karyakar', 'mobile' => '9222222222', 'status' => 'approved', 'approved_by' => $centerAdmin->id, 'approved_at' => now()]);
        $sanchalak = Karyakar::query()->updateOrCreate(['karyakar_reference' => 'SK-PLT-000003'], ['center_id' => $center->id, 'user_id' => $sanchalakUser->id, 'sampark_area_id' => $area->id, 'society_id' => $society->id, 'source' => 'manual', 'full_name' => 'Pilot Sanchalak', 'gender' => 'male', 'age' => 40, 'category' => 'Yuvak Karyakar', 'status' => 'approved', 'approved_by' => $centerAdmin->id, 'approved_at' => now()]);

        $group = SankalpGroup::query()->updateOrCreate(['group_code' => 'PLT-001'], ['center_id' => $center->id, 'sampark_area_id' => $area->id, 'society_id' => $society->id, 'group_type' => 'two_male', 'status' => 'active', 'created_by' => $centerAdmin->id, 'activated_at' => now()->subDays(10)]);
        foreach ([1 => $k1, 2 => $k2] as $position => $karyakar) {
            GroupKaryakar::query()->updateOrCreate(['group_id' => $group->id, 'karyakar_id' => $karyakar->id], ['position' => $position, 'status' => 'active', 'assigned_by' => $centerAdmin->id, 'assigned_at' => now()->subDays(10)]);
        }
        $assignments = collect($families->take(10))->map(function (Family $family, int $index) use ($group, $centerAdmin): GroupFamilyAssignment {
            return GroupFamilyAssignment::query()->updateOrCreate(['group_id' => $group->id, 'family_id' => $family->id], ['slot_number' => $index + 1, 'assignment_type' => $index < 6 ? 'fixed' : 'remaining', 'assignment_source' => $index < 6 ? 'admin' : 'karyakar', 'status' => 'active', 'assigned_by' => $centerAdmin->id, 'assigned_at' => now()->subDays(10)]);
        });
        $target = Target::query()->updateOrCreate(['group_id' => $group->id, 'name' => 'Pilot 10 Family Target'], ['center_id' => $center->id, 'sampark_area_id' => $area->id, 'society_id' => $society->id, 'start_date' => now()->subDays(10)->toDateString(), 'end_date' => now()->addDays(20)->toDateString(), 'target_quantity' => 10, 'completed_quantity' => 3, 'status' => 'active', 'assigned_by' => $centerAdmin->id]);
        foreach ($assignments->take(3) as $index => $assignment) {
            HomeVisit::query()->updateOrCreate(['group_family_assignment_id' => $assignment->id], ['center_id' => $center->id, 'group_id' => $group->id, 'family_id' => $assignment->family_id, 'karyakar_id' => $index % 2 ? $k2->id : $k1->id, 'target_id' => $target->id, 'sampark_area_id' => $area->id, 'society_id' => $society->id, 'message_delivered' => true, 'completion_note' => 'Pilot completed visit', 'completed_at' => now()->subDays(3 - $index), 'recorded_by' => $index % 2 ? $fieldTwo->id : $fieldOne->id]);
        }

        $childIds = FamilyMember::query()->whereIn('family_id', $families->take(3)->pluck('id'))->where('relationship', 'Child')->pluck('id');
        $bal = BalGroup::query()->updateOrCreate(['group_code' => 'PLT-BAL-001'], ['center_id' => $center->id, 'sampark_area_id' => $area->id, 'society_id' => $society->id, 'sanchalak_karyakar_id' => $sanchalak->id, 'sanchalak_user_id' => $sanchalakUser->id, 'status' => 'active', 'created_by' => $centerAdmin->id, 'activated_at' => now()->subDays(5)]);
        foreach ($childIds->values() as $index => $childId) {
            BalGroupChild::query()->updateOrCreate(['bal_group_id' => $bal->id, 'family_member_id' => $childId], ['position' => $index + 1, 'status' => 'active', 'assigned_by' => $centerAdmin->id, 'assigned_at' => now()->subDays(5)]);
        }
        BalCompletionReport::query()->firstOrCreate(['bal_group_id' => $bal->id, 'completion_date' => now()->subDay()->toDateString()], ['center_id' => $center->id, 'sanchalak_karyakar_id' => $sanchalak->id, 'society_id' => $society->id, 'families_visited' => 2, 'families_completed' => 2, 'family_details' => 'Pilot Bal Pravruti completion', 'submitted_by' => $sanchalakUser->id]);

        Announcement::query()->firstOrCreate(['title' => 'Welcome to the Happy Family pilot'], ['center_id' => $center->id, 'body' => 'Pilot data is enabled for testing the complete portal workflow.', 'audience' => 'all', 'status' => 'published', 'published_at' => now(), 'created_by' => $centerAdmin->id]);
        SharedContent::query()->firstOrCreate(['title' => 'Pilot Sankalp'], ['center_id' => $center->id, 'content_type' => 'sankalp', 'body' => 'Use this pilot content to verify the shared content experience.', 'audience' => 'all', 'status' => 'published', 'published_at' => now(), 'created_by' => $centerAdmin->id]);
        InventoryItem::query()->firstOrCreate(['center_id' => $center->id, 'sku' => 'PLT-BOOK'], ['name' => 'Happy Family Book', 'unit' => 'pcs', 'current_stock' => 25, 'minimum_stock' => 5, 'status' => 'active', 'created_by' => $centerAdmin->id]);
    }

    private function roleUser(string $roleSlug, string $name, string $email, string $password, Zone $zone, Center $center): User
    {
        $email = strtolower(trim($email));
        $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make($password), 'status' => 'active', 'email_verified_at' => now()]);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id => ['zone_id' => $zone->id, 'center_id' => $center->id, 'is_primary' => true]]);
        return $user;
    }
}
