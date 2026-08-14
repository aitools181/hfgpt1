<?php

namespace App\Services\Bal;

use App\Models\BalCompletionReport;
use App\Models\BalGroup;
use App\Models\BalGroupChild;
use App\Models\Center;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\Karyakar;
use App\Models\Society;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\OrganizationalScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BalPravrutiService
{
    public function __construct(
        private readonly OrganizationalScope $scope,
        private readonly AuditTrail $auditTrail,
    ) {
    }

    public function filters(User $user, array $input = []): array
    {
        $allowedCenterIds = $this->allowedCenterIds($user);
        $centerId = isset($input['center_id']) && $input['center_id'] !== '' ? (int) $input['center_id'] : null;
        if ($centerId && ! $allowedCenterIds->contains($centerId)) {
            $centerId = null;
        }

        $gender = $user->hasRole('bn_karyalay_admin') ? 'female' : ($input['gender'] ?? null);
        if (! in_array($gender, ['male', 'female'], true)) {
            $gender = null;
        }

        $dateFrom = $this->dateOrNull($input['date_from'] ?? null);
        $dateTo = $this->dateOrNull($input['date_to'] ?? null);
        if ($dateFrom && $dateTo && $dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'allowed_center_ids' => $allowedCenterIds,
            'center_id' => $centerId,
            'gender' => $gender,
            'category' => ($input['category'] ?? '') !== '' ? $input['category'] : null,
            'status' => ($input['status'] ?? '') !== '' ? $input['status'] : null,
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo?->toDateString(),
            'female_scope_locked' => $user->hasRole('bn_karyalay_admin'),
        ];
    }

    public function groupQuery(User $user, array $input = []): Builder
    {
        $filters = isset($input['allowed_center_ids']) ? $input : $this->filters($user, $input);
        $query = BalGroup::query()->whereIn('center_id', $filters['allowed_center_ids']);

        if ($filters['center_id']) {
            $query->where('center_id', $filters['center_id']);
        }
        if ($filters['status']) {
            $query->where('status', $filters['status']);
        }
        if ($filters['gender'] || $filters['category']) {
            $query->whereHas('sanchalak', function (Builder $q) use ($filters): void {
                if ($filters['gender']) $q->where('gender', $filters['gender']);
                if ($filters['category']) $q->where('category', $filters['category']);
            });
        }

        if ($this->isBalAdministrator($user)) {
            return $query;
        }
        if ($user->hasRole('nirdeshak') || $user->hasRole('nirikshak')) {
            return $query->whereHas('supervisors', fn (Builder $q) => $q->where('user_id', $user->id)->where('status', 'active'));
        }
        if ($user->hasRole('sanchalak')) {
            return $query->where('sanchalak_user_id', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function dashboard(User $user, array $input = []): array
    {
        $filters = $this->filters($user, $input);
        $groups = $this->groupQuery($user, $filters)->with(['center.zone', 'sanchalak', 'children.member', 'completionReports'])->orderBy('group_code')->get();
        $groupIds = $groups->pluck('id');
        $reportQuery = $this->reportQuery($user, $filters, $groupIds);

        $reports = (clone $reportQuery)->count();
        $visited = (int) (clone $reportQuery)->sum('families_visited');
        $completed = (int) (clone $reportQuery)->sum('families_completed');

        return [
            'filters' => $filters,
            'scopeLabel' => $this->scopeLabel($user),
            'summary' => [
                'groups' => $groups->count(),
                'activeGroups' => $groups->where('status', 'active')->count(),
                'children' => BalGroupChild::query()->whereIn('bal_group_id', $groupIds)->where('status', 'active')->count(),
                'sanchalaks' => $groups->pluck('sanchalak_karyakar_id')->filter()->unique()->count(),
                'reports' => $reports,
                'familiesVisited' => $visited,
                'familiesCompleted' => $completed,
                'completionRate' => $visited > 0 ? round(($completed / $visited) * 100, 2) : 0.0,
            ],
            'centerPerformance' => $this->centerPerformance($user, $filters, $groups),
            'zonePerformance' => $this->zonePerformance($user, $filters, $groups),
            'groupPerformance' => $this->groupPerformance($groups, $filters),
            'childGenderDistribution' => $this->childGenderDistribution($groupIds),
            'sanchalakCategoryDistribution' => $this->sanchalakCategoryDistribution($groups),
            'completionTrend' => $this->completionTrend($reportQuery, $filters),
        ];
    }

    public function createGroup(User $user, array $data): BalGroup
    {
        abort_unless($user->hasPermission('manage_bal_groups'), 403);
        abort_unless($user->canAccessCenterId((int) $data['center_id']), 403, 'Center is outside your permitted scope.');

        return DB::transaction(function () use ($user, $data): BalGroup {
            $center = Center::query()->lockForUpdate()->findOrFail($data['center_id']);
            $children = FamilyMember::query()->with('family')->whereIn('id', $data['child_member_ids'])->get();
            abort_unless($children->count() === 3 && $children->pluck('id')->unique()->count() === 3, 422, 'A Bal Pravruti Group requires exactly 3 distinct children.');

            foreach ($children as $child) {
                abort_unless((int) $child->family?->center_id === (int) $center->id, 422, 'Every child must belong to the selected Center.');
                abort_unless($child->family?->status === 'active', 422, 'Only children from an active Family can be assigned.');
                abort_unless($child->status === 'active', 422, 'Only active Family Members can be assigned as children.');
                abort_unless($child->age !== null && $child->age >= 0 && $child->age <= 12, 422, 'Bal Pravruti child members must be age 0 to 12.');
            }

            $sanchalak = Karyakar::query()->with('user.roles')->where('status', 'approved')->findOrFail($data['sanchalak_karyakar_id']);
            abort_unless((int) $sanchalak->center_id === (int) $center->id, 422, 'Sanchalak must belong to the selected Center.');
            abort_unless($sanchalak->user_id && $sanchalak->user?->hasRole('sanchalak') && $sanchalak->user?->status === 'active', 422, 'Sanchalak must be an Approved Sankalp Karyakar linked to an active Sanchalak portal user.');

            $this->assertAreaSocietyCenter($center->id, $data['sampark_area_id'] ?? null, $data['society_id'] ?? null);
            $this->assertSupervisor($data['nirdeshak_user_id'] ?? null, 'nirdeshak', $center->id);
            $this->assertSupervisor($data['nirikshak_user_id'] ?? null, 'nirikshak', $center->id);

            DB::table('bal_group_sequences')->insertOrIgnore([
                'center_id' => $center->id,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sequence = DB::table('bal_group_sequences')->where('center_id', $center->id)->lockForUpdate()->first();
            $number = ((int) $sequence->last_number) + 1;
            DB::table('bal_group_sequences')->where('center_id', $center->id)->update(['last_number' => $number, 'updated_at' => now()]);
            $code = sprintf('%s-BAL-%03d', strtoupper($center->code), $number);

            $group = BalGroup::query()->create([
                'center_id' => $center->id,
                'sampark_area_id' => $data['sampark_area_id'] ?? null,
                'society_id' => $data['society_id'] ?? null,
                'group_code' => $code,
                'sanchalak_karyakar_id' => $sanchalak->id,
                'sanchalak_user_id' => $sanchalak->user_id,
                'status' => 'active',
                'created_by' => $user->id,
                'activated_at' => now(),
            ]);

            foreach ($children->values() as $index => $child) {
                $group->children()->create([
                    'family_member_id' => $child->id,
                    'position' => $index + 1,
                    'status' => 'active',
                    'assigned_by' => $user->id,
                    'assigned_at' => now(),
                ]);
            }

            foreach (['nirdeshak' => $data['nirdeshak_user_id'] ?? null, 'nirikshak' => $data['nirikshak_user_id'] ?? null] as $role => $supervisorId) {
                if ($supervisorId) {
                    $group->supervisors()->create([
                        'user_id' => $supervisorId,
                        'role_slug' => $role,
                        'status' => 'active',
                        'assigned_by' => $user->id,
                        'assigned_at' => now(),
                    ]);
                }
            }

            $this->auditTrail->record('bal_pravruti', 'group_created', BalGroup::class, (string) $group->id, [], [
                'group_code' => $group->group_code,
                'center_id' => $group->center_id,
                'sanchalak_karyakar_id' => $group->sanchalak_karyakar_id,
                'children' => $children->pluck('id')->values()->all(),
                'nirdeshak_user_id' => $data['nirdeshak_user_id'] ?? null,
                'nirikshak_user_id' => $data['nirikshak_user_id'] ?? null,
            ], centerId: $group->center_id);

            return $group->fresh(['children.member.family', 'supervisors.user', 'sanchalak.user', 'center', 'area', 'society']);
        });
    }

    public function submitCompletion(User $user, BalGroup $group, array $data): BalCompletionReport
    {
        abort_unless($user->hasPermission('submit_bal_completion'), 403);
        abort_unless((int) $group->sanchalak_user_id === (int) $user->id, 403, 'Only the assigned Sanchalak can submit this Bal Pravruti completion report.');
        abort_unless($group->status === 'active', 422, 'Completion can only be submitted for an active Bal Pravruti Group.');
        abort_unless((int) $data['families_completed'] <= (int) $data['families_visited'], 422, 'Families completed cannot exceed families visited.');

        $this->assertAreaSocietyCenter($group->center_id, null, $data['society_id'] ?? null);
        if (! empty($data['family_id'])) {
            $family = Family::query()->findOrFail($data['family_id']);
            abort_unless((int) $family->center_id === (int) $group->center_id, 422, 'Selected Family must belong to the Bal Group Center.');
            if ($family->society_id) {
                abort_unless((int) $family->society_id === (int) $data['society_id'], 422, 'Selected Family Society must match the completion report Society.');
            }
        }

        return DB::transaction(function () use ($user, $group, $data): BalCompletionReport {
            $report = BalCompletionReport::query()->create([
                'center_id' => $group->center_id,
                'bal_group_id' => $group->id,
                'sanchalak_karyakar_id' => $group->sanchalak_karyakar_id,
                'society_id' => $data['society_id'] ?? null,
                'family_id' => $data['family_id'] ?? null,
                'families_visited' => $data['families_visited'],
                'families_completed' => $data['families_completed'],
                'mobile' => $data['mobile'] ?? null,
                'family_name' => $data['family_name'] ?? null,
                'family_details' => $data['family_details'] ?? null,
                'completion_date' => $data['completion_date'],
                'submitted_by' => $user->id,
            ]);

            $this->auditTrail->record('bal_pravruti', 'completion_submitted', BalCompletionReport::class, (string) $report->id, [], [
                'group_code' => $group->group_code,
                'families_visited' => $report->families_visited,
                'families_completed' => $report->families_completed,
                'society_id' => $report->society_id,
                'family_id' => $report->family_id,
            ], centerId: $group->center_id);

            return $report->fresh(['group', 'society', 'family', 'sanchalak']);
        });
    }

    public function filterOptions(User $user): array
    {
        $groupQuery = $this->groupQuery($user);
        $groups = (clone $groupQuery)->with('sanchalak')->get();
        $centerIds = $groups->pluck('center_id')->unique();
        if ($this->isBalAdministrator($user)) {
            $centerIds = $this->allowedCenterIds($user);
        }

        return [
            'centers' => Center::query()->whereIn('id', $centerIds)->orderBy('name')->get(['id', 'name', 'code']),
            'categories' => $groups->pluck('sanchalak.category')->filter()->unique()->sort()->values(),
        ];
    }

    public function creationOptions(User $user): array
    {
        $centerIds = $this->scope->centers($user)->pluck('id');
        $children = FamilyMember::query()->with('family:id,center_id,external_family_id,manual_reference,head_name')
            ->where('status', 'active')->whereBetween('age', [0, 12])
            ->whereHas('family', fn (Builder $q) => $q->whereIn('center_id', $centerIds)->where('status', 'active'))
            ->orderBy('name')->get();
        $sanchalaks = Karyakar::query()->with('user.roles')->whereIn('center_id', $centerIds)->where('status', 'approved')
            ->whereNotNull('user_id')
            ->whereHas('user', fn (Builder $q) => $q->where('status', 'active')->whereHas('roles', fn (Builder $roles) => $roles->where('roles.slug', 'sanchalak')))
            ->orderBy('full_name')->get();
        $supervisors = User::query()->with('roles')->where('status', 'active')->whereHas('roles', function (Builder $q) use ($centerIds): void {
            $q->whereIn('roles.slug', ['nirdeshak', 'nirikshak'])->whereIn('user_roles.center_id', $centerIds);
        })->orderBy('name')->get();

        return [
            'centers' => Center::query()->whereIn('id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'children' => $children,
            'sanchalaks' => $sanchalaks,
            'supervisors' => $supervisors,
            'areas' => \App\Models\SamparkArea::query()->whereIn('center_id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'center_id', 'name']),
            'societies' => Society::query()->whereIn('center_id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'center_id', 'sampark_area_id', 'name']),
        ];
    }

    public function completionOptions(User $user): array
    {
        $groups = $this->groupQuery($user, ['status' => 'active'])->with(['center', 'society'])->orderBy('group_code')->get();
        $centerIds = $groups->pluck('center_id')->unique();
        return [
            'groups' => $groups,
            'societies' => Society::query()->whereIn('center_id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'center_id', 'name']),
            'families' => Family::query()->whereIn('center_id', $centerIds)->where('status', 'active')->orderBy('head_name')->get(['id', 'center_id', 'society_id', 'external_family_id', 'manual_reference', 'head_name']),
        ];
    }

    public function reportQuery(User $user, array $filters, Collection|array|null $groupIds = null): Builder
    {
        $groupIds ??= $this->groupQuery($user, $filters)->pluck('id');
        $query = BalCompletionReport::query()->whereIn('bal_group_id', $groupIds);
        if ($filters['center_id']) $query->where('center_id', $filters['center_id']);
        if ($filters['date_from']) $query->whereDate('completion_date', '>=', $filters['date_from']);
        if ($filters['date_to']) $query->whereDate('completion_date', '<=', $filters['date_to']);
        return $query;
    }

    private function centerPerformance(User $user, array $filters, Collection $groups): array
    {
        $centerIds = $this->isBalAdministrator($user) ? $this->allowedCenterIds($user) : $groups->pluck('center_id')->unique();
        if ($filters['center_id']) $centerIds = collect([$filters['center_id']]);
        return Center::query()->with('zone')->whereIn('id', $centerIds)->orderBy('name')->get()->map(function (Center $center) use ($user, $filters, $groups): array {
            $centerGroupIds = $groups->where('center_id', $center->id)->pluck('id');
            $reports = $this->reportQuery($user, $filters, $centerGroupIds);
            $visited = (int) (clone $reports)->sum('families_visited');
            $completed = (int) (clone $reports)->sum('families_completed');
            return [
                'center_id' => $center->id,
                'center' => $center->name,
                'center_code' => $center->code,
                'zone_id' => $center->zone_id,
                'zone' => $center->zone?->name,
                'groups' => $centerGroupIds->count(),
                'children' => BalGroupChild::query()->whereIn('bal_group_id', $centerGroupIds)->where('status', 'active')->count(),
                'reports' => (clone $reports)->count(),
                'families_visited' => $visited,
                'families_completed' => $completed,
                'completion_rate' => $visited > 0 ? round(($completed / $visited) * 100, 2) : 0.0,
            ];
        })->values()->all();
    }

    private function zonePerformance(User $user, array $filters, Collection $groups): array
    {
        return collect($this->centerPerformance($user, $filters, $groups))->groupBy(fn (array $row) => (string) ($row['zone_id'] ?? 'none'))->map(function (Collection $rows): array {
            $first = $rows->first();
            $visited = (int) $rows->sum('families_visited');
            $completed = (int) $rows->sum('families_completed');
            return [
                'zone_id' => $first['zone_id'],
                'zone' => $first['zone'] ?? 'Unassigned Zone',
                'centers' => $rows->count(),
                'groups' => (int) $rows->sum('groups'),
                'children' => (int) $rows->sum('children'),
                'families_visited' => $visited,
                'families_completed' => $completed,
                'completion_rate' => $visited > 0 ? round(($completed / $visited) * 100, 2) : 0.0,
            ];
        })->sortBy('zone')->values()->all();
    }

    private function groupPerformance(Collection $groups, array $filters): array
    {
        return $groups->map(function (BalGroup $group) use ($filters): array {
            $reports = $group->completionReports;
            if ($filters['date_from']) $reports = $reports->filter(fn (BalCompletionReport $r) => $r->completion_date->toDateString() >= $filters['date_from']);
            if ($filters['date_to']) $reports = $reports->filter(fn (BalCompletionReport $r) => $r->completion_date->toDateString() <= $filters['date_to']);
            $visited = (int) $reports->sum('families_visited');
            $completed = (int) $reports->sum('families_completed');
            return [
                'group_id' => $group->id,
                'group_code' => $group->group_code,
                'center' => $group->center?->name,
                'sanchalak' => $group->sanchalak?->full_name,
                'children' => $group->children->where('status', 'active')->count(),
                'reports' => $reports->count(),
                'families_visited' => $visited,
                'families_completed' => $completed,
                'completion_rate' => $visited > 0 ? round(($completed / $visited) * 100, 2) : 0.0,
                'last_completion_date' => $reports->sortByDesc('completion_date')->first()?->completion_date?->toDateString(),
            ];
        })->values()->all();
    }

    private function childGenderDistribution(Collection $groupIds): array
    {
        $rows = BalGroupChild::query()->whereIn('bal_group_id', $groupIds)->where('status', 'active')
            ->join('family_members', 'family_members.id', '=', 'bal_group_children.family_member_id')
            ->selectRaw('family_members.gender as gender, COUNT(*) as total')->groupBy('family_members.gender')->pluck('total', 'gender');
        return [
            ['label' => 'Bal (Male)', 'key' => 'male', 'value' => (int) ($rows['male'] ?? 0)],
            ['label' => 'Balika (Female)', 'key' => 'female', 'value' => (int) ($rows['female'] ?? 0)],
        ];
    }

    private function sanchalakCategoryDistribution(Collection $groups): array
    {
        return $groups->pluck('sanchalak')->filter()->groupBy('category')->map(fn (Collection $items, string $category) => [
            'label' => $category ?: 'Uncategorized',
            'value' => $items->count(),
        ])->values()->all();
    }

    private function completionTrend(Builder $reportQuery, array $filters): array
    {
        $end = $filters['date_to'] ? CarbonImmutable::parse($filters['date_to']) : CarbonImmutable::today();
        $start = $filters['date_from'] ? CarbonImmutable::parse($filters['date_from']) : $end->subDays(13);
        if ($start->diffInDays($end) > 90) $start = $end->subDays(90);

        $rows = (clone $reportQuery)->whereBetween('completion_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('completion_date, SUM(families_completed) as total')->groupBy('completion_date')->pluck('total', 'completion_date');
        $result = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $result[] = ['date' => $key, 'completed' => (int) ($rows[$key] ?? 0)];
        }
        return $result;
    }

    private function allowedCenterIds(User $user): Collection
    {
        if ($this->isBalAdministrator($user)) {
            return $this->scope->centers($user)->pluck('id')->map(fn ($id) => (int) $id)->values();
        }

        $baseIds = $this->scope->centers($user)->pluck('id');
        if ($user->hasRole('sanchalak')) {
            return BalGroup::query()->whereIn('center_id', $baseIds)->where('sanchalak_user_id', $user->id)->pluck('center_id')->unique()->map(fn ($id) => (int) $id)->values();
        }
        if ($user->hasRole('nirdeshak') || $user->hasRole('nirikshak')) {
            return BalGroup::query()->whereIn('center_id', $baseIds)->whereHas('supervisors', fn (Builder $q) => $q->where('user_id', $user->id)->where('status', 'active'))->pluck('center_id')->unique()->map(fn ($id) => (int) $id)->values();
        }
        return collect();
    }

    private function isBalAdministrator(User $user): bool
    {
        return $user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin') || $user->hasRole('zonal_admin') || $user->hasRole('center_admin');
    }

    private function assertAreaSocietyCenter(int $centerId, ?int $areaId, ?int $societyId): void
    {
        if ($areaId) {
            $area = \App\Models\SamparkArea::query()->findOrFail($areaId);
            abort_unless((int) $area->center_id === $centerId, 422, 'Area must belong to the selected Center.');
        }
        if ($societyId) {
            abort_unless($areaId, 422, 'Select the Sampark Area when assigning a Society.');
            $society = Society::query()->findOrFail($societyId);
            abort_unless((int) $society->center_id === $centerId, 422, 'Society must belong to the selected Center.');
            abort_unless((int) $society->sampark_area_id === $areaId, 422, 'Society must belong to the selected Area.');
        }
    }

    private function assertSupervisor(?int $userId, string $roleSlug, int $centerId): void
    {
        if (! $userId) return;
        $supervisor = User::query()->with('roles')->findOrFail($userId);
        abort_unless($supervisor->status === 'active', 422, 'Selected supervisor must be an active portal user.');
        abort_unless($supervisor->hasRole($roleSlug), 422, "Selected supervisor must have the {$roleSlug} role.");
        abort_unless($supervisor->canAccessCenterId($centerId), 422, 'Supervisor must be scoped to the selected Center.');
    }

    private function scopeLabel(User $user): string
    {
        if ($user->hasRole('super_admin')) return 'Karyalay / organization-wide Bal Pravruti';
        if ($user->hasRole('bn_karyalay_admin')) return 'BN Karyalay - female Sanchalak analysis scope';
        if ($user->hasRole('zonal_admin')) return 'Assigned Zone Bal Pravruti';
        if ($user->hasRole('center_admin')) return 'Assigned Center Bal Pravruti';
        if ($user->hasRole('nirdeshak')) return 'Assigned Nirdeshak Bal Pravruti Groups';
        if ($user->hasRole('nirikshak')) return 'Assigned Nirikshak Bal Pravruti Groups';
        if ($user->hasRole('sanchalak')) return 'My assigned Bal Pravruti Groups';
        return 'Assigned Bal Pravruti scope';
    }

    private function dateOrNull(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') return null;
        try { return CarbonImmutable::parse($value); } catch (\Throwable) { return null; }
    }
}
