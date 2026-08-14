<?php

namespace App\Services\Monitoring;

use App\Models\Center;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\GroupFamilyAssignment;
use App\Models\HomeVisit;
use App\Models\Karyakar;
use App\Models\SamparkArea;
use App\Models\SankalpGroup;
use App\Models\Target;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportService
{
    public const TYPES = [
        'center_family_registration' => 'Center-wise Family Registration',
        'center_karyakar' => 'Center-wise Karyakar',
        'group_karyakar' => 'Group-wise Karyakar',
        'area_assignment' => 'Area-wise Assignment',
        'target_assignment' => 'Target Assignment',
        'target_completion' => 'Target Completion',
        'pending_family' => 'Pending Sankalp Family',
        'home_visit_completion' => 'Home Visit Completion',
        'center_performance' => 'Center Performance Summary',
        'organization_summary' => 'Organization-wide Summary',
    ];

    public function __construct(private readonly MonitoringAnalyticsService $analytics)
    {
    }

    public function build(User $user, string $type, array $input = []): array
    {
        if (! array_key_exists($type, self::TYPES)) {
            $type = 'center_performance';
        }
        $filters = $this->analytics->filters($user, $input);

        [$columns, $rows] = match ($type) {
            'center_family_registration' => $this->centerFamilyRegistration($filters),
            'center_karyakar' => $this->centerKaryakar($filters),
            'group_karyakar' => $this->groupKaryakar($filters),
            'area_assignment' => $this->areaAssignment($filters),
            'target_assignment' => $this->targetAssignment($filters, false),
            'target_completion' => $this->targetAssignment($filters, true),
            'pending_family' => $this->pendingFamily($filters),
            'home_visit_completion' => $this->homeVisitCompletion($filters),
            'organization_summary' => $this->organizationSummary($user, $filters),
            default => $this->centerPerformance($user, $filters),
        };

        return [
            'type' => $type,
            'title' => self::TYPES[$type],
            'columns' => $columns,
            'rows' => $rows,
            'filters' => $filters,
        ];
    }

    private function centerFamilyRegistration(array $filters): array
    {
        $centerIds = $this->analytics->effectiveCenterIds($filters);
        $centers = Center::query()->whereIn('id', $centerIds)->orderBy('name')->get();
        $rows = $centers->map(function (Center $center) use ($filters): array {
            $families = Family::query()->where('center_id', $center->id);
            $this->applyFamilyFilters($families, $filters);
            $members = FamilyMember::query()->whereHas('family', function (Builder $q) use ($center, $filters): void {
                $q->where('center_id', $center->id);
                $this->applyFamilyFilters($q, $filters);
            });
            if ($filters['gender']) $members->where('gender', $filters['gender']);

            return [
                'center' => $center->name,
                'code' => $center->code,
                'total_families' => (clone $families)->count(),
                'global_import' => (clone $families)->where('source', 'global')->count(),
                'manual' => (clone $families)->where('source', 'manual')->count(),
                'members' => (clone $members)->count(),
                'male_members' => (clone $members)->where('gender', 'male')->count(),
                'female_members' => (clone $members)->where('gender', 'female')->count(),
            ];
        })->values()->all();

        return [[
            'center' => 'Center', 'code' => 'Code', 'total_families' => 'Families', 'global_import' => 'Global Import',
            'manual' => 'Manual', 'members' => 'Members', 'male_members' => 'Male Members', 'female_members' => 'Female Members',
        ], $rows];
    }

    private function centerKaryakar(array $filters): array
    {
        $centerIds = $this->analytics->effectiveCenterIds($filters);
        $rows = Center::query()->whereIn('id', $centerIds)->orderBy('name')->get()->map(function (Center $center) use ($filters): array {
            $query = $this->analytics->karyakarQuery($filters, [$center->id])->where('center_id', $center->id);
            return [
                'center' => $center->name,
                'code' => $center->code,
                'total' => (clone $query)->count(),
                'approved' => (clone $query)->where('status', 'approved')->count(),
                'pending' => (clone $query)->where('status', 'pending')->count(),
                'rejected' => (clone $query)->where('status', 'rejected')->count(),
                'male' => (clone $query)->where('gender', 'male')->count(),
                'female' => (clone $query)->where('gender', 'female')->count(),
            ];
        })->values()->all();

        return [[
            'center' => 'Center', 'code' => 'Code', 'total' => 'Total', 'approved' => 'Approved', 'pending' => 'Pending',
            'rejected' => 'Rejected', 'male' => 'Male', 'female' => 'Female',
        ], $rows];
    }

    private function groupKaryakar(array $filters): array
    {
        $centerIds = $this->analytics->effectiveCenterIds($filters);
        $query = SankalpGroup::query()->with(['center:id,name,code', 'area:id,name', 'society:id,name', 'karyakars' => fn ($q) => $q->where('group_karyakars.status', 'active')->orderBy('group_karyakars.position')])
            ->whereIn('center_id', $centerIds)->orderBy('group_code');
        if ($filters['center_id']) $query->where('center_id', $filters['center_id']);
        if ($filters['group_id']) $query->whereKey($filters['group_id']);
        if ($filters['area_id']) $query->where('sampark_area_id', $filters['area_id']);
        if ($filters['status']) $query->where('status', $filters['status']);
        if ($filters['karyakar_id'] || $filters['gender'] || $filters['category']) {
            $query->whereHas('karyakars', function (Builder $q) use ($filters): void {
                if ($filters['karyakar_id']) $q->where('karyakars.id', $filters['karyakar_id']);
                if ($filters['gender']) $q->where('karyakars.gender', $filters['gender']);
                if ($filters['category']) $q->where('karyakars.category', $filters['category']);
                $q->where('group_karyakars.status', 'active');
            });
        }

        $rows = $query->get()->map(function (SankalpGroup $group): array {
            $one = $group->karyakars->get(0);
            $two = $group->karyakars->get(1);
            return [
                'group' => $group->group_code,
                'center' => $group->center?->name,
                'type' => $group->group_type,
                'status' => $group->status,
                'karyakar_1' => $one?->full_name,
                'gender_1' => $one?->gender,
                'karyakar_2' => $two?->full_name,
                'gender_2' => $two?->gender,
                'area' => $group->area?->name,
                'society' => $group->society?->name,
            ];
        })->all();

        return [[
            'group' => 'Group', 'center' => 'Center', 'type' => 'Group Type', 'status' => 'Status', 'karyakar_1' => 'Karyakar 1',
            'gender_1' => 'Gender 1', 'karyakar_2' => 'Karyakar 2', 'gender_2' => 'Gender 2', 'area' => 'Area', 'society' => 'Society',
        ], $rows];
    }

    private function areaAssignment(array $filters): array
    {
        $centerIds = $this->analytics->effectiveCenterIds($filters);
        $query = SamparkArea::query()->with('center:id,name,code')->whereIn('center_id', $centerIds)->orderBy('name');
        if ($filters['center_id']) $query->where('center_id', $filters['center_id']);
        if ($filters['area_id']) $query->whereKey($filters['area_id']);

        $rows = $query->get()->map(function (SamparkArea $area) use ($filters): array {
            $areaFilters = $filters;
            $areaFilters['center_id'] = $area->center_id;
            $areaFilters['area_id'] = $area->id;
            $groupQuery = SankalpGroup::query()->where('center_id', $area->center_id)->where('sampark_area_id', $area->id);
            if ($filters['group_id']) $groupQuery->whereKey($filters['group_id']);
            if ($filters['karyakar_id'] || $filters['gender'] || $filters['category']) {
                $groupQuery->whereHas('karyakars', function (Builder $q) use ($filters): void {
                    if ($filters['karyakar_id']) $q->where('karyakars.id', $filters['karyakar_id']);
                    if ($filters['gender']) $q->where('karyakars.gender', $filters['gender']);
                    if ($filters['category']) $q->where('karyakars.category', $filters['category']);
                    $q->where('group_karyakars.status', 'active');
                });
            }
            $groupIds = (clone $groupQuery)->pluck('id');
            $familyAssignments = $this->analytics->assignmentQuery($areaFilters, [$area->center_id])->where('status', 'active')->whereIn('group_id', $groupIds);
            $completedAssignments = $this->analytics->completedAssignmentQuery($areaFilters, [$area->center_id])->whereIn('group_id', $groupIds);
            $visits = $this->analytics->homeVisitQuery($areaFilters, [$area->center_id]);

            return [
                'center' => $area->center?->name,
                'area' => $area->name,
                'societies' => $area->societies()->count(),
                'groups' => (clone $groupQuery)->count(),
                'assigned_families' => (clone $familyAssignments)->count(),
                'completed' => (clone $completedAssignments)->count(),
                'visits_in_period' => $visits->count(),
            ];
        })->all();

        return [[
            'center' => 'Center', 'area' => 'Sampark Area', 'societies' => 'Societies', 'groups' => 'Groups',
            'assigned_families' => 'Assigned Families', 'completed' => 'Completed', 'visits_in_period' => 'Visits in Period',
        ], $rows];
    }

    private function targetAssignment(array $filters, bool $completionOnly): array
    {
        $centerIds = $this->analytics->effectiveCenterIds($filters);
        $query = $this->analytics->targetQuery($filters, $centerIds)
            ->with(['center:id,name,code', 'group:id,group_code', 'karyakar:id,full_name', 'area:id,name', 'society:id,name'])
            ->orderByDesc('start_date');
        if ($filters['date_from']) $query->where('end_date', '>=', $filters['date_from']);
        if ($filters['date_to']) $query->where('start_date', '<=', $filters['date_to']);
        $rows = $query->get()->map(fn (Target $target) => [
            'center' => $target->center?->name,
            'group' => $target->group?->group_code,
            'karyakar' => $target->karyakar?->full_name ?? 'Group target',
            'area' => $target->area?->name,
            'society' => $target->society?->name,
            'start' => $target->start_date?->toDateString(),
            'end' => $target->end_date?->toDateString(),
            'target' => $target->target_quantity,
            'completed' => $target->completed_quantity,
            'remaining' => $target->remaining_quantity,
            'percentage' => $target->completion_percentage.'%',
            'status' => $target->status,
        ])->all();

        $columns = $completionOnly
            ? [
                'center' => 'Center', 'group' => 'Group', 'karyakar' => 'Karyakar', 'area' => 'Area', 'society' => 'Society',
                'target' => 'Target', 'completed' => 'Completed', 'remaining' => 'Remaining', 'percentage' => 'Completion %', 'status' => 'Status',
            ]
            : [
                'center' => 'Center', 'group' => 'Group', 'karyakar' => 'Karyakar', 'area' => 'Area', 'society' => 'Society',
                'start' => 'Start Date', 'end' => 'End Date', 'target' => 'Target', 'status' => 'Status',
            ];

        return [$columns, $rows];
    }

    private function pendingFamily(array $filters): array
    {
        $centerIds = $this->analytics->effectiveCenterIds($filters);
        $query = $this->analytics->assignmentQuery($filters, $centerIds)->where('status', 'active')->whereDoesntHave('homeVisit')
            ->with(['family.area:id,name', 'family.society:id,name', 'group.center:id,name,code'])
            ->orderBy('group_id')->orderBy('slot_number');

        $rows = $query->get()->map(fn (GroupFamilyAssignment $assignment) => [
            'center' => $assignment->group?->center?->name,
            'group' => $assignment->group?->group_code,
            'slot' => $assignment->slot_number,
            'family_id' => $assignment->family?->reference,
            'head' => $assignment->family?->head_name,
            'assignment_type' => $assignment->assignment_type,
            'area' => $assignment->family?->area?->name,
            'society' => $assignment->family?->society?->name,
            'status' => 'pending',
        ])->all();

        return [[
            'center' => 'Center', 'group' => 'Group', 'slot' => 'Slot', 'family_id' => 'Family ID', 'head' => 'Head of Family',
            'assignment_type' => 'Assignment Type', 'area' => 'Area', 'society' => 'Society', 'status' => 'Status',
        ], $rows];
    }

    private function homeVisitCompletion(array $filters): array
    {
        $centerIds = $this->analytics->effectiveCenterIds($filters);
        $query = $this->analytics->homeVisitQuery($filters, $centerIds)
            ->with(['center:id,name,code', 'group:id,group_code', 'family:id,external_family_id,manual_reference,head_name', 'karyakar:id,full_name,gender,category', 'area:id,name', 'society:id,name'])
            ->orderByDesc('completed_at');

        $rows = $query->get()->map(fn (HomeVisit $visit) => [
            'completed_at' => $visit->completed_at?->format('Y-m-d H:i'),
            'center' => $visit->center?->name,
            'group' => $visit->group?->group_code,
            'family_id' => $visit->family?->reference,
            'family' => $visit->family?->head_name,
            'karyakar' => $visit->karyakar?->full_name,
            'gender' => $visit->karyakar?->gender,
            'category' => $visit->karyakar?->category,
            'area' => $visit->area?->name,
            'society' => $visit->society?->name,
            'message_delivered' => $visit->message_delivered ? 'Yes' : 'No',
            'override' => $visit->is_admin_override ? 'Yes' : 'No',
        ])->all();

        return [[
            'completed_at' => 'Completed At', 'center' => 'Center', 'group' => 'Group', 'family_id' => 'Family ID',
            'family' => 'Family', 'karyakar' => 'Karyakar', 'gender' => 'Gender', 'category' => 'Category', 'area' => 'Area',
            'society' => 'Society', 'message_delivered' => 'Message Delivered', 'override' => 'Admin Override',
        ], $rows];
    }

    private function centerPerformance(User $user, array $filters): array
    {
        return [[
            'center' => 'Center', 'center_code' => 'Code', 'zone' => 'Zone', 'families' => 'Families', 'karyakars' => 'Approved Karyakars',
            'groups' => 'Active Groups', 'assigned' => 'Assigned Families', 'completed' => 'Main Completed', 'bal_completed' => 'Bal Completed', 'overall_completed' => 'Overall Completed', 'pending' => 'Pending',
            'completion_percentage' => 'Main Completion %', 'target_quantity' => 'Target Quantity', 'target_completed_quantity' => 'Target Completed',
        ], $this->analytics->centerPerformance($user, $filters)];
    }

    private function organizationSummary(User $user, array $filters): array
    {
        $data = $this->analytics->dashboard($user, $filters);
        $s = $data['summary'];
        $rows = [[
            'scope' => $filters['female_scope_locked'] ? 'BN Karyalay - Female Analysis' : 'Permitted Organization Scope',
            'zones' => $s['zones'],
            'centers' => $s['centers'],
            'families' => $s['families'],
            'approved_karyakars' => $s['approvedKaryakars'],
            'active_groups' => $s['activeGroups'],
            'assigned_families' => $s['assignedFamilies'],
            'completed_families' => $s['completedFamilies'],
            'bal_completed_families' => $s['balCompletedFamilies'],
            'overall_completed_families' => $s['overallCompletedFamilies'],
            'pending_families' => $s['pendingFamilies'],
            'completion_percentage' => $s['completionPercentage'].'%',
            'target_quantity' => $s['targetQuantity'],
            'home_visits' => $s['homeVisits'],
        ]];

        return [[
            'scope' => 'Scope', 'zones' => 'Zones', 'centers' => 'Centers', 'families' => 'Families',
            'approved_karyakars' => 'Approved Karyakars', 'active_groups' => 'Active Groups', 'assigned_families' => 'Assigned Families',
            'completed_families' => 'Main Completed Families', 'bal_completed_families' => 'Bal Completed Families', 'overall_completed_families' => 'Overall Completed Families', 'pending_families' => 'Pending Families', 'completion_percentage' => 'Main Completion %',
            'target_quantity' => 'Target Quantity', 'home_visits' => 'Home Visits',
        ], $rows];
    }

    private function applyFamilyFilters(Builder $query, array $filters): void
    {
        if ($filters['center_id']) $query->where('center_id', $filters['center_id']);
        if ($filters['area_id']) $query->where('sampark_area_id', $filters['area_id']);
        if ($filters['status']) $query->where('status', $filters['status']);
        if ($filters['date_from']) $query->whereDate('registered_at', '>=', $filters['date_from']);
        if ($filters['date_to']) $query->whereDate('registered_at', '<=', $filters['date_to']);
        if ($filters['group_id']) {
            $query->whereHas('groupAssignments', fn (Builder $q) => $q->where('group_id', $filters['group_id'])->where('status', 'active'));
        }
        if ($filters['karyakar_id'] || $filters['category']) {
            $query->whereHas('groupAssignments.group.karyakars', function (Builder $q) use ($filters): void {
                if ($filters['karyakar_id']) $q->where('karyakars.id', $filters['karyakar_id']);
                if ($filters['category']) $q->where('karyakars.category', $filters['category']);
                $q->where('group_karyakars.status', 'active');
            });
        }
    }
}
