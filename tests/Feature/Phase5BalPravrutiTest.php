<?php

namespace Tests\Feature;

use App\Models\BalCompletionReport;
use App\Models\BalGroup;
use App\Models\Center;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Karyakar;
use App\Models\Role;
use App\Models\SamparkArea;
use App\Models\Society;
use App\Models\User;
use App\Models\Zone;
use App\Services\Bal\BalPravrutiService;
use App\Services\Monitoring\MonitoringAnalyticsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase5BalPravrutiTest extends TestCase
{
    use RefreshDatabase;

    private int $familySequence = 1;
    private int $karyakarSequence = 1;

    private function base(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $zone = Zone::query()->create(['name' => 'North', 'code' => 'NZ', 'status' => 'active']);
        $center = Center::query()->create(['zone_id' => $zone->id, 'name' => 'Gandhinagar', 'code' => 'GND', 'status' => 'active']);
        $area = SamparkArea::query()->create(['center_id' => $center->id, 'name' => 'Sector 5', 'status' => 'active']);
        $society = Society::query()->create(['center_id' => $center->id, 'sampark_area_id' => $area->id, 'name' => 'Akshar Society', 'status' => 'active']);
        return [$zone, $center, $area, $society];
    }

    private function user(string $roleSlug, string $email, ?Zone $zone = null, ?Center $center = null): User
    {
        $user = User::query()->create(['name' => $roleSlug, 'email' => $email, 'password' => 'StrongPassword123!', 'status' => 'active']);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, ['zone_id' => $center?->zone_id ?? $zone?->id, 'center_id' => $center?->id, 'is_primary' => true]);
        return $user->fresh('roles.permissions');
    }

    private function children(Center $center, array $ages = [8, 9, 10]): array
    {
        $family = Family::query()->create([
            'center_id' => $center->id,
            'manual_reference' => sprintf('HF-%s-%06d', $center->code, $this->familySequence++),
            'source' => 'manual',
            'head_name' => 'Bal Family',
            'status' => 'active',
            'registered_at' => now(),
        ]);
        return collect($ages)->map(fn (int $age, int $index) => FamilyMember::query()->create([
            'family_id' => $family->id,
            'name' => 'Child '.($index + 1),
            'gender' => $index % 2 === 0 ? 'male' : 'female',
            'age' => $age,
            'status' => 'active',
        ]))->all();
    }

    private function sanchalak(Center $center, Zone $zone, string $email = 'sanchalak@example.test'): array
    {
        $user = $this->user('sanchalak', $email, $zone, $center);
        $karyakar = Karyakar::query()->create([
            'center_id' => $center->id,
            'user_id' => $user->id,
            'karyakar_reference' => sprintf('SK-%s-%06d', $center->code, $this->karyakarSequence++),
            'source' => 'manual',
            'full_name' => 'Bal Sanchalak '.$this->karyakarSequence,
            'gender' => 'male',
            'age' => 35,
            'category' => 'Yuvak Karyakar',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
        return [$user, $karyakar];
    }

    private function createGroup(User $admin, Center $center, SamparkArea $area, Society $society, Karyakar $sanchalak, array $children, ?User $nirdeshak = null, ?User $nirikshak = null): BalGroup
    {
        $this->actingAs($admin);
        return app(BalPravrutiService::class)->createGroup($admin, [
            'center_id' => $center->id,
            'sampark_area_id' => $area->id,
            'society_id' => $society->id,
            'sanchalak_karyakar_id' => $sanchalak->id,
            'child_member_ids' => collect($children)->pluck('id')->all(),
            'nirdeshak_user_id' => $nirdeshak?->id,
            'nirikshak_user_id' => $nirikshak?->id,
        ]);
    }

    public function test_center_admin_can_create_exact_three_children_one_sanchalak_group(): void
    {
        [$zone, $center, $area, $society] = $this->base();
        $admin = $this->user('center_admin', 'admin@example.test', $zone, $center);
        [, $sanchalak] = $this->sanchalak($center, $zone);
        $children = $this->children($center);

        $response = $this->actingAs($admin)->post('/bal-pravruti/groups', [
            'center_id' => $center->id,
            'sampark_area_id' => $area->id,
            'society_id' => $society->id,
            'sanchalak_karyakar_id' => $sanchalak->id,
            'child_member_ids' => collect($children)->pluck('id')->all(),
        ]);

        $group = BalGroup::query()->firstOrFail();
        $response->assertRedirect('/bal-pravruti/groups/'.$group->id);
        $this->assertSame('GND-BAL-001', $group->group_code);
        $this->assertSame(3, $group->children()->where('status', 'active')->count());
        $this->assertSame($sanchalak->id, $group->sanchalak_karyakar_id);
        $this->assertTrue($admin->hasPermission('access_bal_pravruti'));
    }

    public function test_group_creation_rejects_non_child_member_over_age_twelve(): void
    {
        [$zone, $center, $area, $society] = $this->base();
        $admin = $this->user('center_admin', 'admin2@example.test', $zone, $center);
        [, $sanchalak] = $this->sanchalak($center, $zone, 'sanchalak2@example.test');
        $children = $this->children($center, [8, 9, 13]);

        $this->actingAs($admin)->post('/bal-pravruti/groups', [
            'center_id' => $center->id,
            'sampark_area_id' => $area->id,
            'society_id' => $society->id,
            'sanchalak_karyakar_id' => $sanchalak->id,
            'child_member_ids' => collect($children)->pluck('id')->all(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('bal_groups', 0);
    }

    public function test_only_assigned_sanchalak_can_submit_bal_completion_report(): void
    {
        [$zone, $center, $area, $society] = $this->base();
        $admin = $this->user('center_admin', 'admin3@example.test', $zone, $center);
        [$assignedUser, $assignedKaryakar] = $this->sanchalak($center, $zone, 'assigned@example.test');
        [$otherUser] = $this->sanchalak($center, $zone, 'other@example.test');
        $group = $this->createGroup($admin, $center, $area, $society, $assignedKaryakar, $this->children($center));

        $payload = [
            'society_id' => $society->id,
            'families_visited' => 4,
            'families_completed' => 3,
            'mobile' => null,
            'family_name' => 'Patel Family',
            'family_details' => 'Happy Family message delivered to the completed families.',
            'completion_date' => now()->toDateString(),
        ];

        $this->actingAs($otherUser)->post("/bal-pravruti/groups/{$group->id}/completions", $payload)->assertForbidden();
        $this->actingAs($assignedUser)->post("/bal-pravruti/groups/{$group->id}/completions", $payload)->assertRedirect();
        $this->assertDatabaseHas('bal_completion_reports', ['bal_group_id' => $group->id, 'families_visited' => 4, 'families_completed' => 3]);
    }

    public function test_nirdeshak_is_limited_to_explicitly_assigned_bal_groups(): void
    {
        [$zone, $center, $area, $society] = $this->base();
        $admin = $this->user('center_admin', 'admin4@example.test', $zone, $center);
        $nirdeshak = $this->user('nirdeshak', 'nirdeshak@example.test', $zone, $center);
        [, $sanchalakA] = $this->sanchalak($center, $zone, 'sa@example.test');
        [, $sanchalakB] = $this->sanchalak($center, $zone, 'sb@example.test');
        $this->createGroup($admin, $center, $area, $society, $sanchalakA, $this->children($center), $nirdeshak);
        $this->createGroup($admin, $center, $area, $society, $sanchalakB, $this->children($center));

        $groups = app(BalPravrutiService::class)->groupQuery($nirdeshak)->get();
        $this->assertCount(1, $groups);
        $this->assertSame($sanchalakA->id, $groups->first()->sanchalak_karyakar_id);
    }

    public function test_bal_completed_count_contributes_to_main_center_and_overall_analysis(): void
    {
        [$zone, $center, $area, $society] = $this->base();
        $admin = $this->user('center_admin', 'admin5@example.test', $zone, $center);
        [$sanchalakUser, $sanchalak] = $this->sanchalak($center, $zone, 'sc@example.test');
        $group = $this->createGroup($admin, $center, $area, $society, $sanchalak, $this->children($center));
        BalCompletionReport::query()->create([
            'center_id' => $center->id,
            'bal_group_id' => $group->id,
            'sanchalak_karyakar_id' => $sanchalak->id,
            'society_id' => $society->id,
            'families_visited' => 6,
            'families_completed' => 5,
            'family_details' => 'Five completed.',
            'completion_date' => now()->toDateString(),
            'submitted_by' => $sanchalakUser->id,
        ]);

        $analysis = app(MonitoringAnalyticsService::class)->dashboard($admin);
        $this->assertSame(5, $analysis['summary']['balCompletedFamilies']);
        $this->assertSame($analysis['summary']['completedFamilies'] + 5, $analysis['summary']['overallCompletedFamilies']);
        $this->assertSame(5, $analysis['centerPerformance'][0]['bal_completed']);
        $this->assertSame($analysis['centerPerformance'][0]['completed'] + 5, $analysis['centerPerformance'][0]['overall_completed']);
    }
}
