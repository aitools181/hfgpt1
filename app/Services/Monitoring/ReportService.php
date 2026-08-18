<?php

namespace App\Services\Monitoring;

use App\Models\Center;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\GroupFamilyAssignment;
use App\Models\HomeVisit;
use App\Models\SamparkArea;
use App\Models\SankalpGroup;
use App\Models\Target;
use App\Models\User;
use Generator;
use Illuminate\Database\Eloquent\Builder;

class ReportService
{
    public const PREVIEW_LIMIT = 500;

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

    /**
     * Build a bounded browser preview. Large datasets are never materialized in
     * full for an Inertia response; CSV export uses stream() below.
     */
    public function build(User $user, string $type, array $input = []): array
    {
        $definition = $this->stream($user, $type, $input);
        $rows = [];
        $truncated = false;

        foreach ($definition['rows'] as $row) {
            if (count($rows) >= self::PREVIEW_LIMIT) {
                $truncated = true;
                break;
            }
            $rows[] = $row;
        }

        unset($definition['rows']);

        return $definition + [
            'rows' => $rows,
            'truncated' => $truncated,
            'row_limit' => self::PREVIEW_LIMIT,
        ];
    }

    /**
     * Return a lazy row stream suitable for CSV output. Detailed reports use
     * lazyById/lazyByIdDesc so memory stays bounded even at production scale.
     */
    public function stream(User $user, string $type, array $input = []): array
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
        $columns = [
            'center' => 'Center', 'code' => 'Code', 'total_families' => 'Families', 'global_import' => 'Global Import',
            'manual' => 'Manual', 'members' => 'Members', 'male_members' => 'Male Members', 'female_members' => 'Female Members',
        ];
        $centerIds = $this->analytics->effectiveCenterIds($filters);

        $rows = function () use ($centerIds, $filters): Generator {
            foreach (Center::query()->whereIn('id', $centerIds)->orderBy('id')->lazyById(100) as $center) {
                $families = Family::query()->where('center_id', $center->id);
                $this->applyFamilyFilters($families, $filters);
                $members = FamilyMember::query()->whereHas('family', function (Builder $query) use ($center, $filters): void {
                    $query->where('center_id', $center->id);
                    $this->applyFamilyFilters($query, $filters);
                });
                if ($filters['gender']) {
                    $members->where('gender', $filters['gender']);
                }

                yield [
                    'center' => $center->name,
                    'code' => $center->code,
                    'total_families' => (clone $families)->count(),
                    'global_import' => (clone $families)->where('source', 'global')->count(),
                    'manual' => (clone $families)->where('source', 'manual')->count(),
                    'members' => (clone $members)->count(),
                    'male_members' => (clone $members)->where('gender', 'male')->count(),
                    'female_members' => (clone $members)->where('gender', 'female')->count(),
                ];
            }
        };

        return [$columns, $rows()];
    }

    private function centerKaryakar(array $filters): array
    {
        $columns = [
            'center' => 'Center', 'code' => 'Code', 'total' => 'Total', 'approved' => 'Approved', 'pending' => 'Pending',
            'rejected' => 'Rejected', 'male' => 'Male', 'female' => 'Female',
        ];
        $centerIds = $this->analytics->effectiveCenterIds($filters);

        $rows = function () use ($centerIds, $filters): Generator {
            foreach (Center::query()->whereIn('id', $centerIds)->orderBy('id')->lazyById(100) as $center) {
                $query = $this->analytics->karyakarQuery($filters, [$center->id])->where('center_id', $center->id);
                yield [
                    'center' => $center->name,
                    'code' => $center->code,
                    'total' => (clone $query)->count(),
                    'approved' => (clone $query)->where('status', 'approved')->count(),
                    'pending' => (clone $query)->where('status', 'pending')->count(),
                    'rejected' => (clone $query)->where('status', 'rejected')->count(),
                    'male' => (clone $query)->where('gender', 'male')->count(),
                    'female' => (clone $query)->where('gender', 'female')->count(),
                ];
            }
        };

        return [$columns, $rows()];
    }

    private function groupKaryakar(array $filters): array
    {
        $columns = [
            'group' => 'Group', 'center' => 'Center', 'type' => 'Group Type', 'status' => 'Status', 'karyakar_1' => 'Karyakar 1',
            'gender_1' => 'Gender 1', 'karyakar_2' => 'Karyakar 2', 'gender_2' => 'Gender 2', 'area' => 'Area', 'society' => 'Society',
        ];
        $query = $this->groupKaryakarQuery($filters);

        $rows = function () use ($query): Generator {
            foreach ($query->lazyById(200) as $group) {
                $one = $group->karyakars->get(0);
                $two = $group->karyakars->get(1);
                yield [
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
            }
        };

        return [$columns, $rows()];
    }

    private function areaAssignment(array $filters): array
    {
        $columns = [
            'center' => 'Center', 'area' => 'Sampark Area', 'societies' => 'Societies', 'groups' => 'Groups',
            'assigned_families' => 'Assigned Families', 'completed' => 'Completed', 'visits_in_period' => 'Visits in Period',
        ];
        $centerIds = $this->analytics->effectiveCenterIds($filters);
        $query = SamparkArea::query()->with('center:id,name,code')->whereIn('center_id', $centerIds);
        if ($filters['center_id']) $query->where('center_id', $filters['center_id']);
        if ($filters['area_id']) $query->whereKey($filters['area_id']);

        $rows = function () use ($query, $filters): Generator {
            foreach ($query->orderBy('id')->lazyById(200) as $area) {
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

                $groupIdSubquery = (clone $groupQuery)->select('groups.id');
                $familyAssignments = $this->analytics->assignmentQuery($areaFilters, [$area->center_id])
                    ->where('status', 'active')->whereIn('group_id', clone $groupIdSubquery);
                $completedAssignments = $this->analytics->completedAssignmentQuery($areaFilters, [$area->center_id])
                    ->whereIn('group_id', clone $groupIdSubquery);
                $visits = $this->analytics->homeVisitQuery($areaFilters, [$area->center_id]);

                yield [
                    'center' => $area->center?->name,
                    'area' => $area->name,
                    'societies' => $area->societies()->count(),
                    'groups' => (clone $groupQuery)->count(),
                    'assigned_families' => (clone $familyAssignments)->count(),
                    'completed' => (clone $completedAssignments)->count(),
                    'visits_in_period' => $visits->count(),
                ];
            }
        };

        return [$columns, $rows()];
    }

    private function targetAssignment(array $filters, bool $completionOnly): array
    {
        $columns = $completionOnly
            ? [
                'center' => 'Center', 'group' => 'Group', 'karyakar' => 'Karyakar', 'area' => 'Area', 'society' => 'Society',
                'target' => 'Target', 'completed' => 'Completed', 'remaining' => 'Remaining', 'percentage' => 'Completion %', 'status' => 'Status',
            ]
            : [
                'center' => 'Center', 'group' => 'Group', 'karyakar' => 'Karyakar', 'area' => 'Area', 'society' => 'Society',
                'start' => 'Start Date', 'end' => 'End Date', 'target' => 'Target', 'status' => 'Status',
            ];
        $query = $this->targetQuery($filters);

        $rows = function () use ($query): Generator {
            foreach ($query->lazyById(250) as $target) {
                yield $this->targetRow($target);
            }
        };

        return [$columns, $rows()];
    }

    private function pendingFamily(array $filters): array
    {
        $columns = [
            'center' => 'Center', 'group' => 'Group', 'slot' => 'Slot', 'family_id' => 'Family ID', 'head' => 'Head of Family',
            'assignment_type' => 'Assignment Type', 'area' => 'Area', 'society' => 'Society', 'status' => 'Status',
        ];
        $query = $this->pendingFamilyQuery($filters);

        $rows = function () use ($query): Generator {
            foreach ($query->lazyById(250) as $assignment) {
                yield [
                    'center' => $assignment->group?->center?->name,
                    'group' => $assignment->group?->group_code,
                    'slot' => $assignment->slot_number,
                    'family_id' => $assignment->family?->reference,
                    'head' => $assignment->family?->head_name,
                    'assignment_type' => $assignment->assignment_type,
                    'area' => $assignment->family?->area?->name,
                    'society' => $assignment->family?->society?->name,
                    'status' => 'pending',
                ];
            }
        };

        return [$columns, $rows()];
    }

    private function homeVisitCompletion(array $filters): array
    {
        $columns = [
            'completed_at' => 'Completed At', 'center' => 'Center', 'group' => 'Group', 'family_id' => 'Family ID',
            'family' => 'Family', 'karyakar' => 'Karyakar', 'gender' => 'Gender', 'category' => 'Category', 'area' => 'Area',
            'society' => 'Society', 'message_delivered' => 'Message Delivered', 'override' => 'Admin Override',
        ];
        $query = $this->homeVisitQuery($filters);

        $rows = function () use ($query): Generator {
            foreach ($query->lazyByIdDesc(250) as $visit) {
                yield [
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
                ];
            }
        };

        return [$columns, $rows()];
    }

    private function centerPerformance(User $user, array $filters): array
    {
        $columns = [
            'center' => 'Center', 'center_code' => 'Code', 'zone' => 'Zone', 'families' => 'Families', 'karyakars' => 'Approved Karyakars',
            'groups' => 'Active Groups', 'assigned' => 'Assigned Families', 'completed' => 'Main Completed', 'bal_completed' => 'Bal Completed',
            'overall_completed' => 'Overall Completed', 'pending' => 'Pending', 'completion_percentage' => 'Main Completion %',
            'target_quantity' => 'Target Quantity', 'target_completed_quantity' => 'Target Completed',
        ];
        $rows = $this->analytics->centerPerformance($user, $filters);

        return [$columns, (function () use ($rows): Generator { foreach ($rows as $row) yield $row; })()];
    }

    private function organizationSummary(User $user, array $filters): array
    {
        $columns = [
            'scope' => 'Scope', 'zones' => 'Zones', 'centers' => 'Centers', 'families' => 'Families',
            'approved_karyakars' => 'Approved Karyakars', 'active_groups' => 'Active Groups', 'assigned_families' => 'Assigned Families',
            'completed_families' => 'Main Completed Families', 'bal_completed_families' => 'Bal Completed Families',
            'overall_completed_families' => 'Overall Completed Families', 'pending_families' => 'Pending Families',
            'completion_percentage' => 'Main Completion %', 'target_quantity' => 'Target Quantity', 'home_visits' => 'Home Visits',
        ];
        $data = $this->analytics->dashboard($user, $filters);
        $summary = $data['summary'];
        $row = [
            'scope' => $filters['female_scope_locked'] ? 'BN Karyalay - Female Analysis' : 'Permitted Organization Scope',
            'zones' => $summary['zones'],
            'centers' => $summary['centers'],
            'families' => $summary['families'],
            'approved_karyakars' => $summary['approvedKaryakars'],
            'active_groups' => $summary['activeGroups'],
            'assigned_families' => $summary['assignedFamilies'],
            'completed_families' => $summary['completedFamilies'],
            'bal_completed_families' => $summary['balCompletedFamilies'],
            'overall_completed_families' => $summary['overallCompletedFamilies'],
            'pending_families' => $summary['pendingFamilies'],
            'completion_percentage' => $summary['completionPercentage'].'%',
            'target_quantity' => $summary['targetQuantity'],
            'home_visits' => $summary['homeVisits'],
        ];

        return [$columns, (function () use ($row): Generator { yield $row; })()];
    }

    private function groupKaryakarQuery(array $filters): Builder
    {
        $centerIds = $this->analytics->effectiveCenterIds($filters);
        $query = SankalpGroup::query()
            ->with([
                'center:id,name,code', 'area:id,name', 'society:id,name',
                'karyakars' => fn ($q) => $q->where('group_karyakars.status', 'active')->orderBy('group_karyakars.position'),
            ])
            ->whereIn('center_id', $centerIds);
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

        return $query;
    }

    private function targetQuery(array $filters): Builder
    {
        $centerIds = $this->analytics->effectiveCenterIds($filters);
        $query = $this->analytics->targetQuery($filters, $centerIds)
            ->with(['center:id,name,code', 'group:id,group_code', 'karyakar:id,full_name', 'area:id,name', 'society:id,name']);
        if ($filters['date_from']) $query->where('end_date', '>=', $filters['date_from']);
        if ($filters['date_to']) $query->where('start_date', '<=', $filters['date_to']);

        return $query;
    }

    private function pendingFamilyQuery(array $filters): Builder
    {
        $centerIds = $this->analytics->effectiveCenterIds($filters);

        return $this->analytics->assignmentQuery($filters, $centerIds)
            ->where('status', 'active')
            ->whereDoesntHave('homeVisit')
            ->with(['family.area:id,name', 'family.society:id,name', 'group.center:id,name,code']);
    }

    private function homeVisitQuery(array $filters): Builder
    {
        $centerIds = $this->analytics->effectiveCenterIds($filters);

        return $this->analytics->homeVisitQuery($filters, $centerIds)
            ->with([
                'center:id,name,code', 'group:id,group_code',
                'family:id,external_family_id,manual_reference,head_name',
                'karyakar:id,full_name,gender,category', 'area:id,name', 'society:id,name',
            ]);
    }

    private function targetRow(Target $target): array
    {
        return [
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
        ];
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
