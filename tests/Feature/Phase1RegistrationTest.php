<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Karyakar;
use App\Models\Role;
use App\Models\User;
use App\Models\Zone;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase1RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function centerAdmin(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $zone = Zone::query()->create(['name' => 'North', 'code' => 'N', 'status' => 'active']);
        $center = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Gandhinagar', 'code' => 'GND', 'status' => 'active']);
        $role = Role::query()->where('slug', 'center_admin')->firstOrFail();
        $user = User::query()->create(['name' => 'Center Admin', 'email' => 'phase1@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        $user->roles()->attach($role->id, ['zone_id' => $zone->id, 'center_id' => $center->id, 'is_primary' => true]);
        return [$user, $center];
    }

    public function test_manual_family_is_bound_to_permitted_center_and_gets_reference(): void
    {
        [$user, $center] = $this->centerAdmin();
        $this->actingAs($user)->post('/registration/families', [
            'center_id' => $center->id, 'head_name' => 'Patel Family', 'head_mobile' => '9999999999',
            'members' => [['name' => 'Rajesh Patel', 'gender' => 'male', 'age' => 40, 'relationship' => 'Head', 'is_head' => true]],
        ])->assertRedirect();
        $family = Family::query()->firstOrFail();
        $this->assertSame($center->id, $family->center_id);
        $this->assertStringStartsWith('HF-GND-', $family->manual_reference);
        $this->assertSame(1, $family->members()->count());
    }

    public function test_family_member_nomination_auto_calculates_category_and_starts_pending(): void
    {
        [$user, $center] = $this->centerAdmin();
        $family = Family::query()->create(['center_id' => $center->id, 'manual_reference' => 'HF-GND-000001', 'source' => 'manual', 'head_name' => 'Shah Family', 'status' => 'active']);
        $member = FamilyMember::query()->create(['family_id' => $family->id, 'name' => 'Jaya Shah', 'gender' => 'female', 'age' => 30, 'status' => 'active']);
        $this->actingAs($user)->post('/registration/karyakars/nominate', ['family_member_id' => $member->id])->assertRedirect();
        $karyakar = Karyakar::query()->firstOrFail();
        $this->assertSame('Yuvti Karyakar', $karyakar->category);
        $this->assertSame('pending', $karyakar->status);
        $this->assertSame('family_nomination', $karyakar->source);
    }

    public function test_center_admin_can_approve_pending_karyakar(): void
    {
        [$user, $center] = $this->centerAdmin();
        $karyakar = Karyakar::query()->create(['center_id' => $center->id, 'karyakar_reference' => 'SK-GND-000001', 'source' => 'manual', 'full_name' => 'A Patel', 'gender' => 'male', 'age' => 35, 'category' => 'Yuvak Karyakar', 'status' => 'pending']);
        $this->actingAs($user)->post("/registration/karyakars/{$karyakar->id}/decision", ['decision' => 'approved', 'decision_note' => 'Verified'])->assertRedirect();
        $this->assertSame('approved', $karyakar->fresh()->status);
        $this->assertSame($user->id, $karyakar->fresh()->approved_by);
    }
}
