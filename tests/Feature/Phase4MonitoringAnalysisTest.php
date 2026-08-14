<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Center;
use App\Models\Family;
use App\Models\Karyakar;
use App\Models\Role;
use App\Models\SamparkArea;
use App\Models\SankalpGroup;
use App\Models\User;
use App\Models\Zone;
use App\Services\Monitoring\MonitoringAnalyticsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Phase4MonitoringAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private int $familySequence = 1;
    private int $karyakarSequence = 1;
    private int $groupSequence = 1;

    private function base(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $zoneA = Zone::query()->create(['name' => 'North', 'code' => 'NZ', 'status' => 'active']);
        $zoneB = Zone::query()->create(['name' => 'South', 'code' => 'SZ', 'status' => 'active']);
        $centerA = Center::query()->create(['zone_id' => $zoneA->id, 'name' => 'Gandhinagar', 'code' => 'GND', 'status' => 'active']);
        $centerB = Center::query()->create(['zone_id' => $zoneB->id, 'name' => 'Patan', 'code' => 'PTN', 'status' => 'active']);
        $areaA = SamparkArea::query()->create(['center_id' => $centerA->id, 'name' => 'Sector 5', 'status' => 'active']);
        $areaB = SamparkArea::query()->create(['center_id' => $centerB->id, 'name' => 'Station Road', 'status' => 'active']);
        return [$zoneA, $zoneB, $centerA, $centerB, $areaA, $areaB];
    }

    private function userWithRole(string $roleSlug, string $email, ?Zone $zone = null, ?Center $center = null): User
    {
        $user = User::query()->create(['name' => $roleSlug, 'email' => $email, 'password' => 'StrongPassword123!', 'status' => 'active']);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id, ['zone_id' => $zone?->id, 'center_id' => $center?->id, 'is_primary' => true]);
        return $user->fresh('roles.permissions');
    }

    private function karyakar(Center $center, string $name, string $gender, ?User $user = null): Karyakar
    {
        return Karyakar::query()->create([
            'center_id' => $center->id,
            'user_id' => $user?->id,
            'karyakar_reference' => sprintf('SK-%s-%06d', $center->code, $this->karyakarSequence++),
            'source' => 'manual',
            'full_name' => $name,
            'gender' => $gender,
            'age' => 35,
            'category' => $gender === 'female' ? 'Yuvti Karyakar' : 'Yuvak Karyakar',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    private function groupWithFamily(Center $center, SamparkArea $area, Karyakar $karyakar, bool $complete = false): array
    {
        $group = SankalpGroup::query()->create([
            'center_id' => $center->id,
            'sampark_area_id' => $area->id,
            'group_code' => sprintf('%s-%03d', $center->code, $this->groupSequence++),
            'group_type' => $karyakar->gender === 'female' ? 'two_female' : 'two_male',
            'status' => 'active',
            'activated_at' => now()->subDays(2),
        ]);
        $group->karyakarAssignments()->create(['karyakar_id' => $karyakar->id, 'position' => 1, 'status' => 'active', 'assigned_at' => now()->subDays(2)]);

        $family = Family::query()->create([
            'center_id' => $center->id,
            'sampark_area_id' => $area->id,
            'manual_reference' => sprintf('HF-%s-%06d', $center->code, $this->familySequence++),
            'source' => 'manual',
            'head_name' => 'Family '.$this->familySequence,
            'status' => 'active',
            'registered_at' => now(),
        ]);
        $assignment = $group->familyAssignments()->create([
            'family_id' => $family->id,
            'slot_number' => 1,
            'assignment_type' => 'fixed',
            'assignment_source' => 'admin',
            'status' => 'active',
            'assigned_at' => now()->subDays(2),
        ]);
        if ($complete) {
            $assignment->homeVisit()->create([
                'center_id' => $center->id,
                'group_id' => $group->id,
                'family_id' => $family->id,
                'karyakar_id' => $karyakar->id,
                'sampark_area_id' => $area->id,
                'message_delivered' => true,
                'completed_at' => now(),
            ]);
        }
        return [$group, $family, $assignment];
    }

    public function test_center_admin_monitoring_is_restricted_to_own_center(): void
    {
        [$zoneA, , $centerA, $centerB, $areaA, $areaB] = $this->base();
        $admin = $this->userWithRole('center_admin', 'center@example.test', $zoneA, $centerA);
        $a = $this->karyakar($centerA, 'A Karyakar', 'male');
        $b = $this->karyakar($centerB, 'B Karyakar', 'male');
        $this->groupWithFamily($centerA, $areaA, $a, true);
        $this->groupWithFamily($centerA, $areaA, $a, false);
        $this->groupWithFamily($centerB, $areaB, $b, true);

        $data = app(MonitoringAnalyticsService::class)->dashboard($admin);

        $this->assertSame(1, $data['summary']['centers']);
        $this->assertSame(2, $data['summary']['assignedFamilies']);
        $this->assertSame(1, $data['summary']['completedFamilies']);
        $this->assertSame(1, $data['summary']['pendingFamilies']);
        $this->assertSame('Gandhinagar', $data['centerPerformance'][0]['center']);
        $this->assertCount(1, $data['centerPerformance']);
    }

    public function test_bn_karyalay_analysis_is_locked_to_female_karyakar_scope(): void
    {
        [, , $centerA, $centerB, $areaA, $areaB] = $this->base();
        $bn = $this->userWithRole('bn_karyalay_admin', 'bn@example.test');
        $female = $this->karyakar($centerA, 'Female Karyakar', 'female');
        $male = $this->karyakar($centerB, 'Male Karyakar', 'male');
        $this->groupWithFamily($centerA, $areaA, $female, true);
        $this->groupWithFamily($centerB, $areaB, $male, true);

        $data = app(MonitoringAnalyticsService::class)->dashboard($bn, ['gender' => 'male']);

        $this->assertTrue($data['filters']['female_scope_locked']);
        $this->assertSame('female', $data['filters']['gender']);
        $this->assertSame(1, $data['summary']['approvedKaryakars']);
        $this->assertSame(1, $data['summary']['homeVisits']);
        $this->assertSame(0, $data['genderDistribution'][0]['value']);
        $this->assertSame(1, $data['genderDistribution'][1]['value']);
    }

    public function test_karyakar_reports_are_locked_to_own_assignments_not_whole_center(): void
    {
        [$zoneA, , $centerA, , $areaA] = $this->base();
        $fieldUser = $this->userWithRole('karyakar', 'field@example.test', $zoneA, $centerA);
        $own = $this->karyakar($centerA, 'Own Field', 'male', $fieldUser);
        $other = $this->karyakar($centerA, 'Other Field', 'male');
        $this->groupWithFamily($centerA, $areaA, $own, true);
        $this->groupWithFamily($centerA, $areaA, $other, true);

        $data = app(MonitoringAnalyticsService::class)->dashboard($fieldUser);

        $this->assertTrue($data['filters']['own_karyakar_locked']);
        $this->assertSame($own->id, $data['filters']['karyakar_id']);
        $this->assertSame(1, $data['summary']['assignedFamilies']);
        $this->assertSame(1, $data['summary']['homeVisits']);
        $this->assertSame(1, $data['summary']['approvedKaryakars']);
    }

    public function test_report_csv_export_does_not_include_out_of_scope_center(): void
    {
        [$zoneA, , $centerA, $centerB] = $this->base();
        $admin = $this->userWithRole('center_admin', 'report@example.test', $zoneA, $centerA);
        Family::query()->create(['center_id' => $centerA->id, 'manual_reference' => 'HF-GND-1', 'source' => 'manual', 'head_name' => 'GND Family', 'status' => 'active']);
        Family::query()->create(['center_id' => $centerB->id, 'manual_reference' => 'HF-PTN-1', 'source' => 'manual', 'head_name' => 'PTN Family', 'status' => 'active']);

        $response = $this->actingAs($admin)->get('/monitoring/reports/export?report=center_family_registration');
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Gandhinagar', $content);
        $this->assertStringNotContainsString('Patan', $content);
    }

    public function test_karyakar_audit_view_only_contains_own_actions(): void
    {
        [$zoneA, , $centerA] = $this->base();
        $field = $this->userWithRole('karyakar', 'audit-field@example.test', $zoneA, $centerA);
        $admin = $this->userWithRole('center_admin', 'audit-admin@example.test', $zoneA, $centerA);
        AuditLog::query()->create(['user_id' => $field->id, 'user_name' => 'Field', 'user_role' => 'Karyakar', 'center_id' => $centerA->id, 'module' => 'field', 'action' => 'complete', 'record_reference' => 'OWN', 'created_at' => now()]);
        AuditLog::query()->create(['user_id' => $admin->id, 'user_name' => 'Admin', 'user_role' => 'Center Admin', 'center_id' => $centerA->id, 'module' => 'groups', 'action' => 'update', 'record_reference' => 'OTHER', 'created_at' => now()]);

        $this->actingAs($field)->get('/admin/audit-logs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/audit-logs')
                ->has('logs', 1)
                ->where('logs.0.record_reference', 'OWN'));
    }
}
