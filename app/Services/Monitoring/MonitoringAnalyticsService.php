<?php

namespace App\Services\Monitoring;

use App\Models\BalCompletionReport;
use App\Models\Center;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\GroupFamilyAssignment;
use App\Models\HomeVisit;
use App\Models\Karyakar;
use App\Models\SankalpGroup;
use App\Models\Target;
use App\Models\User;
use App\Services\OrganizationalScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MonitoringAnalyticsService
{
    public function __construct(private readonly OrganizationalScope $scope)
    {
    }

    public function filters(User $user, array $input = []): array
    {
        $allowedCenterIds = $this->scope->centers($user)->pluck('id')->map(fn ($id) => (int) $id)->values();
        $centerId = isset($input['center_id']) ? (int) $input['center_id'] : null;
        if ($centerId && ! $allowedCenterIds->contains($centerId)) {
            $centerId = null;
        }

        $gender = $user->hasRole('bn_karyalay_admin') ? 'female' : ($input['gender'] ?? null);
        if (! in_array($gender, ['male', 'female'], true)) {
            $gender = null;
        }

        $ownKaryakarId = null;
        if ($user->hasRole('karyakar')) {
            $ownKaryakarId = Karyakar::query()->where('user_id', $user->id)->where('status', 'approved')->value('id');
        }

        $dateFrom = $this->dateOrNull($input['date_from'] ?? null);
        $dateTo = $this->dateOrNull($input['date_to'] ?? null);
        if ($dateFrom && $dateTo && $dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'allowed_center_ids' => $allowedCenterIds,
            'center_id' => $centerId,
            'group_id' => isset($input['group_id']) && $input['group_id'] !== '' ? (int) $input['group_id'] : null,
            'karyakar_id' => $user->hasRole('karyakar') ? ($ownKaryakarId ?: -1) : (isset($input['karyakar_id']) && $input['karyakar_id'] !== '' ? (int) $input['karyakar_id'] : null),
            'area_id' => isset($input['area_id']) && $input['area_id'] !== '' ? (int) $input['area_id'] : null,
            'status' => $input['status'] ?? null,
            'group_status' => in_array(($input['group_status'] ?? null), ['active', 'non_active', 'draft', 'closed'], true) ? $input['group_status'] : null,
            'gender' => $gender,
            'category' => $input['category'] ?? null,
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo?->toDateString(),
            'female_scope_locked' => $user->hasRole('bn_karyalay_admin'),
            'own_karyakar_locked' => $user->hasRole('karyakar'),
        ];
    }

    public function dashboard(User $user, array $input = []): array
    {
        $filters = $this->filters($user, $input);
        $centerIds = $this->effectiveCenterIds($filters);

        $familyQuery = Family::query()->whereIn('center_id', $centerIds);
        if ($filters['status']) {
            $familyQuery->where('status', $filters['status']);
        }
        if ($filters['area_id']) {
            $familyQuery->where('sampark_area_id', $filters['area_id']);
        }
        if ($filters['group_id']) {
            $familyQuery->whereHas('groupAssignments', fn (Builder $q) => $q->where('group_id', $filters['group_id'])->where('status', 'active'));
        }
        if ($filters['karyakar_id'] || $filters['gender'] || $filters['category']) {
            $familyQuery->whereHas('groupAssignments.group.karyakars', function (Builder $q) use ($filters): void {
                if ($filters['karyakar_id']) $q->where('karyakars.id', $filters['karyakar_id']);
                if ($filters['gender']) $q->where('karyakars.gender', $filters['gender']);
                if ($filters['category']) $q->where('karyakars.category', $filters['category']);
                $q->where('group_karyakars.status', 'active');
            });
        }

        $karyakarQuery = $this->karyakarQuery($filters, $centerIds);
        $groupQuery = SankalpGroup::query()->whereIn('center_id', $centerIds);
        $this->applyGroupStatusFilter($groupQuery, $filters['group_status']);
        if ($filters['group_id']) {
            $groupQuery->whereKey($filters['group_id']);
        }
        if ($filters['karyakar_id']) {
            $groupQuery->whereHas('karyakarAssignments', fn (Builder $q) => $q->where('karyakar_id', $filters['karyakar_id'])->where('status', 'active'));
        }
        if ($filters['gender'] || $filters['category']) {
            $groupQuery->whereHas('karyakars', function (Builder $q) use ($filters): void {
                if ($filters['gender']) $q->where('karyakars.gender', $filters['gender']);
                if ($filters['category']) $q->where('karyakars.category', $filters['category']);
                $q->where('group_karyakars.status', 'active');
            });
        }
        if ($filters['area_id']) {
            $groupQuery->where('sampark_area_id', $filters['area_id']);
        }

        $assignmentQuery = $this->assignmentQuery($filters, $centerIds)->where('status', 'active');
        $visitQuery = $this->homeVisitQuery($filters, $centerIds);
        $targetQuery = $this->targetQuery($filters, $centerIds);

        $assignedFamilies = (clone $assignmentQuery)->count();
        $completedAssignments = $this->completedAssignmentQuery($filters, $centerIds)->count();
        $pendingAssignments = max(0, $assignedFamilies - $completedAssignments);
        $completionPercentage = $assignedFamilies > 0 ? round(($completedAssignments / $assignedFamilies) * 100, 2) : 0.0;

        $targetQuantity = (int) (clone $targetQuery)->sum('target_quantity');
        $targetCompleted = (int) (clone $targetQuery)->sum('completed_quantity');
        $balCompletedFamilies = $this->includeBalInMain($user) ? (int) $this->balCompletionQuery($filters, $centerIds)->sum('families_completed') : 0;

        // Center metrics are the most expensive monitoring calculation. Compute
        // them once per request and derive zone/leaderboard views in memory. The
        // previous dashboard recomputed the same Center metrics up to five times.
        $centerPerformance = $this->centerPerformance($user, $filters);
        $zonePerformance = $this->aggregateZonePerformance($centerPerformance);
        $centerLeaderboard = collect($centerPerformance)
            ->sortByDesc('completion_percentage')->values()
            ->map(fn (array $row, int $index) => ['rank' => $index + 1] + $row)->all();
        $zoneLeaderboard = collect($zonePerformance)
            ->sortByDesc('completion_percentage')->values()
            ->map(fn (array $row, int $index) => ['rank' => $index + 1] + $row)->all();

        $groupRows = (clone $groupQuery)
            ->with([
                'center:id,name,code',
                'area:id,name',
                'society:id,name',
                'karyakars' => fn ($q) => $q->where('group_karyakars.status', 'active')->orderBy('group_karyakars.position')->select('karyakars.id', 'karyakars.full_name', 'karyakars.karyakar_reference'),
            ])
            ->withCount(['familyAssignments as active_families_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('group_code')
            ->limit(250)
            ->get()
            ->map(fn (SankalpGroup $group) => [
                'id' => $group->id,
                'group_code' => $group->group_code,
                'group_type' => $group->group_type,
                'status' => $group->status,
                'center' => $group->center?->name,
                'center_code' => $group->center?->code,
                'area' => $group->area?->name,
                'society' => $group->society?->name,
                'active_families_count' => $group->active_families_count,
                'members' => $group->karyakars->map(fn (Karyakar $k) => [
                    'id' => $k->id,
                    'name' => $k->full_name,
                    'reference' => $k->karyakar_reference,
                ])->values(),
            ])->values()->all();

        return [
            'filters' => $filters,
            'summary' => [
                'zones' => $this->zoneCountForCenters($centerIds),
                'centers' => count($centerIds),
                'families' => (clone $familyQuery)->count(),
                'members' => $this->memberCount($filters, $centerIds),
                'karyakars' => (clone $karyakarQuery)->count(),
                'approvedKaryakars' => (clone $karyakarQuery)->where('status', 'approved')->count(),
                'groups' => (clone $groupQuery)->count(),
                'activeGroups' => (clone $groupQuery)->where('status', 'active')->count(),
                'activeTargets' => (clone $targetQuery)->where('status', 'active')->count(),
                'targetQuantity' => $targetQuantity,
                'targetCompletedQuantity' => $targetCompleted,
                'assignedFamilies' => $assignedFamilies,
                'completedFamilies' => $completedAssignments,
                'balCompletedFamilies' => $balCompletedFamilies,
                'overallCompletedFamilies' => $completedAssignments + $balCompletedFamilies,
                'pendingFamilies' => $pendingAssignments,
                'completionPercentage' => $completionPercentage,
                'homeVisits' => (clone $visitQuery)->count(),
            ],
            'groupRows' => $groupRows,
            'centerPerformance' => $centerPerformance,
            'zonePerformance' => $zonePerformance,
            'genderDistribution' => $this->genderDistribution($filters, $centerIds),
            'categoryDistribution' => $this->categoryDistribution($filters, $centerIds),
            'completionTrend' => $this->completionTrend($user, $filters, $centerIds),
            'centerLeaderboard' => $centerLeaderboard,
            'zoneLeaderboard' => $zoneLeaderboard,
        ];
    }

    public function centerPerformance(User $user, array $filters = []): array
    {
        $filters = $filters['allowed_center_ids'] ?? null ? $filters : $this->filters($user, $filters);
        $centerIds = $this->effectiveCenterIds($filters);
        $centers = Center::query()->with('zone')->whereIn('id', $centerIds)->orderBy('name')->get();

        return $centers->map(function (Center $center) use ($filters, $user): array {
            $centerFilter = $filters;
            $centerFilter['center_id'] = $center->id;
            $ids = [$center->id];
            $assigned = $this->assignmentQuery($centerFilter, $ids)->where('status', 'active')->count();
            $completed = $this->completedAssignmentQuery($centerFilter, $ids)->count();
            $targetQuantity = (int) $this->targetQuery($centerFilter, $ids)->sum('target_quantity');
            $targetCompleted = (int) $this->targetQuery($centerFilter, $ids)->sum('completed_quantity');
            $balCompleted = $this->includeBalInMain($user) ? (int) $this->balCompletionQuery($centerFilter, $ids)->sum('families_completed') : 0;
            $groupQuery = SankalpGroup::query()->where('center_id', $center->id);
            if ($filters['group_status']) {
                $this->applyGroupStatusFilter($groupQuery, $filters['group_status']);
            } else {
                $groupQuery->where('status', 'active');
            }
            if ($filters['area_id']) $groupQuery->where('sampark_area_id', $filters['area_id']);
            if ($filters['group_id']) $groupQuery->whereKey($filters['group_id']);
            if ($filters['karyakar_id'] || $filters['gender'] || $filters['category']) {
                $groupQuery->whereHas('karyakars', function (Builder $q) use ($filters): void {
                    if ($filters['karyakar_id']) $q->where('karyakars.id', $filters['karyakar_id']);
                    if ($filters['gender']) $q->where('karyakars.gender', $filters['gender']);
                    if ($filters['category']) $q->where('karyakars.category', $filters['category']);
                    $q->where('group_karyakars.status', 'active');
                });
            }
            $familyQuery = Family::query()->where('center_id', $center->id);
            if ($filters['area_id']) $familyQuery->where('sampark_area_id', $filters['area_id']);
            if ($filters['group_id']) $familyQuery->whereHas('groupAssignments', fn (Builder $q) => $q->where('group_id', $filters['group_id'])->where('status', 'active'));
            if ($filters['karyakar_id'] || $filters['gender'] || $filters['category']) {
                $familyQuery->whereHas('groupAssignments.group.karyakars', function (Builder $q) use ($filters): void {
                    if ($filters['karyakar_id']) $q->where('karyakars.id', $filters['karyakar_id']);
                    if ($filters['gender']) $q->where('karyakars.gender', $filters['gender']);
                    if ($filters['category']) $q->where('karyakars.category', $filters['category']);
                    $q->where('group_karyakars.status', 'active');
                });
            }

            return [
                'center_id' => $center->id,
                'center' => $center->name,
                'center_code' => $center->code,
                'zone_id' => $center->zone_id,
                'zone' => $center->zone?->name,
                'families' => $familyQuery->count(),
                'karyakars' => $this->karyakarQuery($centerFilter, $ids)->where('status', 'approved')->count(),
                'groups' => $groupQuery->count(),
                'assigned' => $assigned,
                'completed' => $completed,
                'bal_completed' => $balCompleted,
                'overall_completed' => $completed + $balCompleted,
                'pending' => max(0, $assigned - $completed),
                'completion_percentage' => $assigned > 0 ? round(($completed / $assigned) * 100, 2) : 0.0,
                'target_quantity' => $targetQuantity,
                'target_completed_quantity' => $targetCompleted,
            ];
        })->values()->all();
    }

    public function zonePerformance(User $user, array $filters = []): array
    {
        return $this->aggregateZonePerformance($this->centerPerformance($user, $filters));
    }

    private function aggregateZonePerformance(array $centerRows): array
    {
        return collect($centerRows)->groupBy(fn (array $row) => (string) ($row['zone_id'] ?? 'none'))->map(function (Collection $rows): array {
            $first = $rows->first();
            $assigned = (int) $rows->sum('assigned');
            $completed = (int) $rows->sum('completed');
            $balCompleted = (int) $rows->sum('bal_completed');

            return [
                'zone_id' => $first['zone_id'],
                'zone' => $first['zone'] ?? 'Unassigned Zone',
                'centers' => $rows->count(),
                'karyakars' => (int) $rows->sum('karyakars'),
                'groups' => (int) $rows->sum('groups'),
                'assigned' => $assigned,
                'completed' => $completed,
                'bal_completed' => $balCompleted,
                'overall_completed' => $completed + $balCompleted,
                'pending' => max(0, $assigned - $completed),
                'completion_percentage' => $assigned > 0 ? round(($completed / $assigned) * 100, 2) : 0.0,
            ];
        })->sortBy('zone')->values()->all();
    }

    public function centerLeaderboard(User $user, array $filters = []): array
    {
        return collect($this->centerPerformance($user, $filters))
            ->sortByDesc('completion_percentage')
            ->values()
            ->map(fn (array $row, int $index) => ['rank' => $index + 1] + $row)
            ->all();
    }

    public function zoneLeaderboard(User $user, array $filters = []): array
    {
        return collect($this->zonePerformance($user, $filters))
            ->sortByDesc('completion_percentage')
            ->values()
            ->map(fn (array $row, int $index) => ['rank' => $index + 1] + $row)
            ->all();
    }

    public function filterOptions(User $user, array $input = []): array
    {
        $filters = $this->filters($user, $input);
        $centerIds = $filters['allowed_center_ids'];
        $optionCenterIds = $filters['center_id'] ? collect([$filters['center_id']]) : $centerIds;

        $groups = SankalpGroup::query()->whereIn('center_id', $optionCenterIds)->orderBy('group_code');
        $this->applyGroupStatusFilter($groups, $filters['group_status']);
        $karyakars = Karyakar::query()->whereIn('center_id', $optionCenterIds)->where('status', 'approved')->orderBy('full_name');
        $areas = \App\Models\SamparkArea::query()->whereIn('center_id', $optionCenterIds)->orderBy('name');
        if ($filters['female_scope_locked']) {
            $karyakars->where('gender', 'female');
        }
        if ($filters['own_karyakar_locked']) {
            $groups->whereHas('karyakarAssignments', fn (Builder $q) => $q->where('karyakar_id', $filters['karyakar_id'])->where('status', 'active'));
            $karyakars->whereKey($filters['karyakar_id']);
            $areaIds = SankalpGroup::query()->whereIn('center_id', $centerIds)
                ->whereHas('karyakarAssignments', fn (Builder $q) => $q->where('karyakar_id', $filters['karyakar_id'])->where('status', 'active'))
                ->whereNotNull('sampark_area_id')->pluck('sampark_area_id');
            $areas->whereIn('id', $areaIds);
        }

        return [
            // Filter dropdowns may contain thousands of lightweight rows. Use the
            // base query builder so PHP does not hydrate thousands of Eloquent
            // model objects just to serialize id/name labels.
            'centers' => Center::query()->whereIn('id', $centerIds)->orderBy('name')->toBase()->get(['id', 'name', 'code']),
            'groups' => $groups->with(['karyakars' => fn ($q) => $q->where('group_karyakars.status', 'active')->orderBy('group_karyakars.position')->select('karyakars.id', 'karyakars.full_name')])
                ->get(['id', 'center_id', 'group_code', 'status'])
                ->map(fn (SankalpGroup $group) => [
                    'id' => $group->id,
                    'center_id' => $group->center_id,
                    'group_code' => $group->group_code,
                    'status' => $group->status,
                    'member_names' => $group->karyakars->pluck('full_name')->values()->all(),
                ])->values(),
            'karyakars' => $karyakars->toBase()->get(['id', 'center_id', 'full_name', 'gender', 'category']),
            'areas' => $areas->toBase()->get(['id', 'center_id', 'name']),
            'categories' => Karyakar::query()->whereIn('center_id', $optionCenterIds)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->values(),
        ];
    }

    private function applyGroupStatusFilter(Builder $query, ?string $status): Builder
    {
        if ($status === 'non_active') {
            return $query->where('status', '!=', 'active');
        }
        if (in_array($status, ['active', 'draft', 'closed'], true)) {
            return $query->where('status', $status);
        }
        return $query;
    }

    private function genderDistribution(array $filters, array|Collection $centerIds): array
    {
        $base = Karyakar::query()->whereIn('center_id', $centerIds)->where('status', 'approved');
        if ($filters['center_id']) {
            $base->where('center_id', $filters['center_id']);
        }
        if ($filters['category']) {
            $base->where('category', $filters['category']);
        }
        if ($filters['group_id']) {
            $base->whereHas('groupAssignments', fn (Builder $q) => $q->where('group_id', $filters['group_id'])->where('status', 'active'));
        }

        if ($filters['female_scope_locked']) {
            $base->where('gender', 'female');
        }

        if ($filters['gender']) {
            $base->where('gender', $filters['gender']);
        }

        $rows = (clone $base)->selectRaw('gender, COUNT(*) as total')->groupBy('gender')->pluck('total', 'gender');
        return [
            ['label' => 'Male', 'key' => 'male', 'value' => (int) ($rows['male'] ?? 0)],
            ['label' => 'Female', 'key' => 'female', 'value' => (int) ($rows['female'] ?? 0)],
        ];
    }

    private function categoryDistribution(array $filters, array|Collection $centerIds): array
    {
        $query = Karyakar::query()->whereIn('center_id', $centerIds)->where('status', 'approved');
        if ($filters['center_id']) {
            $query->where('center_id', $filters['center_id']);
        }
        if ($filters['gender']) {
            $query->where('gender', $filters['gender']);
        }
        if ($filters['category']) {
            $query->where('category', $filters['category']);
        }
        if ($filters['group_id']) {
            $query->whereHas('groupAssignments', fn (Builder $q) => $q->where('group_id', $filters['group_id'])->where('status', 'active'));
        }

        return $query->selectRaw('category, COUNT(*) as total')->groupBy('category')->orderByDesc('total')->get()
            ->map(fn ($row) => ['label' => $row->category, 'value' => (int) $row->total])->all();
    }

    private function completionTrend(User $user, array $filters, array|Collection $centerIds): array
    {
        $end = $filters['date_to'] ? CarbonImmutable::parse($filters['date_to']) : CarbonImmutable::today();
        $start = $filters['date_from'] ? CarbonImmutable::parse($filters['date_from']) : $end->subDays(13);
        if ($start->diffInDays($end) > 90) {
            $start = $end->subDays(90);
        }

        $query = $this->homeVisitQuery($filters, $centerIds)->whereBetween('completed_at', [$start->startOfDay(), $end->endOfDay()]);
        $counts = $query->selectRaw('DATE(completed_at) as completion_day, COUNT(*) as total')
            ->groupByRaw('DATE(completed_at)')
            ->pluck('total', 'completion_day');
        $balCounts = collect();
        if ($this->includeBalInMain($user)) {
            $balCounts = $this->balCompletionQuery($filters, $centerIds)
                ->whereBetween('completion_date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw('completion_date, SUM(families_completed) as total')
                ->groupBy('completion_date')->pluck('total', 'completion_date');
        }

        $rows = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $main = (int) ($counts[$key] ?? 0);
            $bal = (int) ($balCounts[$key] ?? 0);
            $rows[] = ['date' => $key, 'completed' => $main + $bal, 'main_completed' => $main, 'bal_completed' => $bal];
        }
        return $rows;
    }

    public function karyakarQuery(array $filters, array|Collection|null $centerIds = null): Builder
    {
        $centerIds ??= $this->effectiveCenterIds($filters);
        $query = Karyakar::query()->whereIn('center_id', $centerIds);
        if ($filters['center_id']) {
            $query->where('center_id', $filters['center_id']);
        }
        if ($filters['gender']) {
            $query->where('gender', $filters['gender']);
        }
        if ($filters['category']) {
            $query->where('category', $filters['category']);
        }
        if ($filters['karyakar_id']) {
            $query->whereKey($filters['karyakar_id']);
        }
        if ($filters['area_id']) {
            $query->where('sampark_area_id', $filters['area_id']);
        }
        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }
        if ($filters['group_id']) {
            $query->whereHas('groupAssignments', fn (Builder $q) => $q->where('group_id', $filters['group_id'])->where('status', 'active'));
        }
        return $query;
    }

    public function homeVisitQuery(array $filters, array|Collection|null $centerIds = null): Builder
    {
        $centerIds ??= $this->effectiveCenterIds($filters);
        $query = HomeVisit::query()->whereIn('center_id', $centerIds);
        if ($filters['center_id']) {
            $query->where('center_id', $filters['center_id']);
        }
        if ($filters['group_id']) {
            $query->where('group_id', $filters['group_id']);
        }
        if ($filters['karyakar_id']) {
            $query->where('karyakar_id', $filters['karyakar_id']);
        }
        if ($filters['area_id']) {
            $query->where('sampark_area_id', $filters['area_id']);
        }
        if ($filters['gender'] || $filters['category']) {
            $query->whereHas('karyakar', function (Builder $q) use ($filters): void {
                if ($filters['gender']) {
                    $q->where('gender', $filters['gender']);
                }
                if ($filters['category']) {
                    $q->where('category', $filters['category']);
                }
            });
        }
        if ($filters['date_from']) {
            $query->where('completed_at', '>=', CarbonImmutable::parse($filters['date_from'])->startOfDay());
        }
        if ($filters['date_to']) {
            $query->where('completed_at', '<=', CarbonImmutable::parse($filters['date_to'])->endOfDay());
        }
        return $query;
    }

    public function assignmentQuery(array $filters, array|Collection|null $centerIds = null): Builder
    {
        $centerIds ??= $this->effectiveCenterIds($filters);
        $query = GroupFamilyAssignment::query()->whereHas('group', fn (Builder $q) => $q->whereIn('center_id', $centerIds));
        if ($filters['center_id']) {
            $query->whereHas('group', fn (Builder $q) => $q->where('center_id', $filters['center_id']));
        }
        if ($filters['group_id']) {
            $query->where('group_id', $filters['group_id']);
        }
        if ($filters['area_id']) {
            $query->whereHas('group', fn (Builder $q) => $q->where('sampark_area_id', $filters['area_id']));
        }
        if ($filters['karyakar_id'] || $filters['gender'] || $filters['category']) {
            $query->whereHas('group.karyakars', function (Builder $q) use ($filters): void {
                if ($filters['karyakar_id']) {
                    $q->where('karyakars.id', $filters['karyakar_id']);
                }
                if ($filters['gender']) {
                    $q->where('karyakars.gender', $filters['gender']);
                }
                if ($filters['category']) {
                    $q->where('karyakars.category', $filters['category']);
                }
                $q->where('group_karyakars.status', 'active');
            });
        }
        return $query;
    }

    public function completedAssignmentQuery(array $filters, array|Collection|null $centerIds = null): Builder
    {
        $centerIds ??= $this->effectiveCenterIds($filters);
        return $this->assignmentQuery($filters, $centerIds)
            ->where('status', 'active')
            ->whereHas('homeVisit', function (Builder $q) use ($filters): void {
                if ($filters['karyakar_id']) $q->where('karyakar_id', $filters['karyakar_id']);
                if ($filters['gender'] || $filters['category']) {
                    $q->whereHas('karyakar', function (Builder $kq) use ($filters): void {
                        if ($filters['gender']) $kq->where('gender', $filters['gender']);
                        if ($filters['category']) $kq->where('category', $filters['category']);
                    });
                }
                if ($filters['date_from']) $q->whereDate('completed_at', '>=', $filters['date_from']);
                if ($filters['date_to']) $q->whereDate('completed_at', '<=', $filters['date_to']);
            });
    }

    public function targetQuery(array $filters, array|Collection|null $centerIds = null): Builder
    {
        $centerIds ??= $this->effectiveCenterIds($filters);
        $query = Target::query()->whereIn('center_id', $centerIds);
        if ($filters['center_id']) {
            $query->where('center_id', $filters['center_id']);
        }
        if ($filters['group_id']) {
            $query->where('group_id', $filters['group_id']);
        }
        if ($filters['karyakar_id']) {
            $query->where('karyakar_id', $filters['karyakar_id']);
        }
        if ($filters['area_id']) {
            $query->where('sampark_area_id', $filters['area_id']);
        }
        if ($filters['gender'] || $filters['category']) {
            $query->where(function (Builder $outer) use ($filters): void {
                $outer->whereHas('karyakar', function (Builder $q) use ($filters): void {
                    if ($filters['gender']) {
                        $q->where('gender', $filters['gender']);
                    }
                    if ($filters['category']) {
                        $q->where('category', $filters['category']);
                    }
                })->orWhere(function (Builder $groupTarget) use ($filters): void {
                    $groupTarget->whereNull('karyakar_id')->whereHas('group.karyakars', function (Builder $q) use ($filters): void {
                        if ($filters['gender']) {
                            $q->where('karyakars.gender', $filters['gender']);
                        }
                        if ($filters['category']) {
                            $q->where('karyakars.category', $filters['category']);
                        }
                        $q->where('group_karyakars.status', 'active');
                    });
                });
            });
        }
        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }
        return $query;
    }

    public function balCompletionQuery(array $filters, array|Collection|null $centerIds = null): Builder
    {
        $centerIds ??= $this->effectiveCenterIds($filters);
        $query = BalCompletionReport::query()->whereIn('center_id', $centerIds);
        if ($filters['center_id']) $query->where('center_id', $filters['center_id']);
        if ($filters['group_id']) return $query->whereRaw('1 = 0');
        if ($filters['area_id']) $query->whereHas('group', fn (Builder $q) => $q->where('sampark_area_id', $filters['area_id']));
        if ($filters['karyakar_id']) $query->where('sanchalak_karyakar_id', $filters['karyakar_id']);
        if ($filters['gender'] || $filters['category']) {
            $query->whereHas('sanchalak', function (Builder $q) use ($filters): void {
                if ($filters['gender']) $q->where('gender', $filters['gender']);
                if ($filters['category']) $q->where('category', $filters['category']);
            });
        }
        if ($filters['date_from']) $query->whereDate('completion_date', '>=', $filters['date_from']);
        if ($filters['date_to']) $query->whereDate('completion_date', '<=', $filters['date_to']);
        return $query;
    }

    private function includeBalInMain(User $user): bool
    {
        return $user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin') || $user->hasRole('zonal_admin') || $user->hasRole('center_admin');
    }

    public function effectiveCenterIds(array $filters): array
    {
        if ($filters['center_id']) {
            return [$filters['center_id']];
        }
        return collect($filters['allowed_center_ids'])->map(fn ($id) => (int) $id)->values()->all();
    }

    private function memberCount(array $filters, array $centerIds): int
    {
        $query = FamilyMember::query()->whereHas('family', function (Builder $q) use ($filters, $centerIds): void {
            $q->whereIn('center_id', $centerIds);
            if ($filters['center_id']) $q->where('center_id', $filters['center_id']);
            if ($filters['area_id']) $q->where('sampark_area_id', $filters['area_id']);
            if ($filters['status']) $q->where('status', $filters['status']);
            if ($filters['group_id']) {
                $q->whereHas('groupAssignments', fn (Builder $aq) => $aq->where('group_id', $filters['group_id'])->where('status', 'active'));
            }
            if ($filters['karyakar_id'] || $filters['category']) {
                $q->whereHas('groupAssignments.group.karyakars', function (Builder $kq) use ($filters): void {
                    if ($filters['karyakar_id']) $kq->where('karyakars.id', $filters['karyakar_id']);
                    if ($filters['category']) $kq->where('karyakars.category', $filters['category']);
                    $kq->where('group_karyakars.status', 'active');
                });
            }
        });
        if ($filters['gender']) $query->where('gender', $filters['gender']);
        return $query->count();
    }

    private function zoneCountForCenters(array $centerIds): int
    {
        return Center::query()->whereIn('id', $centerIds)->whereNotNull('zone_id')->distinct()->count('zone_id');
    }

    private function dateOrNull(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
