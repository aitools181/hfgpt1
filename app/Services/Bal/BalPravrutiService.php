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
        $groupQuery = $this->groupQuery($user, $filters);
        $groupIdSubquery = (clone $groupQuery)->select('bal_groups.id');
        $reportQuery = $this->reportQuery($user, $filters, clone $groupIdSubquery);

        $groups = (clone $groupQuery)->count();
        $activeGroups = (clone $groupQuery)->where('status', 'active')->count();
        $children = BalGroupChild::query()->whereIn('bal_group_id', clone $groupIdSubquery)->where('status', 'active')->count();
        $sanchalaks = (clone $groupQuery)->whereNotNull('sanchalak_karyakar_id')->distinct()->count('sanchalak_karyakar_id');
        $reports = (clone $reportQuery)->count();
        $visited = (int) (clone $reportQuery)->sum('families_visited');
        $completed = (int) (clone $reportQuery)->sum('families_completed');

        $centerPerformance = $this->centerPerformance($user, $filters);

        return [
            'filters' => $filters,
            'scopeLabel' => $this->scopeLabel($user),
            'summary' => [
                'groups' => $groups,
                'activeGroups' => $activeGroups,
                'children' => $children,
                'sanchalaks' => $sanchalaks,
                'reports' => $reports,
                'familiesVisited' => $visited,
                'familiesCompleted' => $completed,
                'completionRate' => $visited > 0 ? round(($completed / $visited) * 100, 2) : 0.0,
            ],
            'centerPerformance' => $centerPerformance,
            'zonePerformance' => $this->zonePerformance($centerPerformance),
            'groupPerformance' => $this->groupPerformance($user, $filters),
            'groupPerformanceLimit' => 300,
            'groupPerformanceTruncated' => $groups > 300,
            'childGenderDistribution' => $this->childGenderDistribution(clone $groupIdSubquery),
            'sanchalakCategoryDistribution' => $this->sanchalakCategoryDistribution($groupQuery),
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
        }, 3);
    }

    public function submitCompletion(User $user, BalGroup $group, array $data): BalCompletionReport
    {
        abort_unless($user->hasPermission('submit_bal_completion'), 403);
        abort_unless((int) $group->sanchalak_user_id === (int) $user->id, 403, 'Only the assigned Sanchalak can submit this Bal Pravruti completion report.');
        abort_unless($group->status === 'active', 422, 'Completion can only be submitted for an active Bal Pravruti Group.');
        abort_unless((int) $data['families_completed'] <= (int) $data['families_visited'], 422, 'Families completed cannot exceed families visited.');

        $this->assertAreaSocietyCenter($group->center_id, $group->sampark_area_id, $data['society_id'] ?? null);
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
        }, 3);
    }

    public function filterOptions(User $user): array
    {
        $groupQuery = $this->groupQuery($user);
        $centerIds = $this->isBalAdministrator($user)
            ? $this->allowedCenterIds($user)
            : (clone $groupQuery)->select('bal_groups.center_id')->distinct()->pluck('bal_groups.center_id');

        // Keep filter catalogs database-driven. Loading every Bal Group together
        // with its Sanchalak caused analysis pages to scale linearly in PHP memory.
        $categories = Karyakar::query()
            ->whereNotNull('category')
            ->whereIn('id', (clone $groupQuery)->whereNotNull('bal_groups.sanchalak_karyakar_id')->select('bal_groups.sanchalak_karyakar_id'))
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->values();

        return [
            'centers' => Center::query()->whereIn('id', $centerIds)->orderBy('name')->get(['id', 'name', 'code']),
            'categories' => $categories,
        ];
    }

    public function creationOptions(User $user): array
    {
        $centerIds = $this->scope->centers($user)->pluck('id');

        // Large child/Karyakar/user catalogs are intentionally not embedded in
        // the initial Inertia payload. They are searched on demand through
        // searchCreationOptions(), keeping this page bounded at 100k-family scale.
        return [
            'centers' => Center::query()->whereIn('id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'areas' => \App\Models\SamparkArea::query()->whereIn('center_id', $centerIds)->where('status', 'active')->orderBy('name')->limit(2000)->get(['id', 'center_id', 'name']),
            'societies' => Society::query()->whereIn('center_id', $centerIds)->where('status', 'active')->orderBy('name')->limit(2000)->get(['id', 'center_id', 'sampark_area_id', 'name']),
        ];
    }

    public function searchCreationOptions(User $user, int $centerId, string $type, string $search = ''): array
    {
        abort_unless($user->hasPermission('manage_bal_groups'), 403);
        abort_unless($this->scope->centers($user)->whereKey($centerId)->exists(), 403, 'Center is outside your permitted scope.');
        $search = trim(mb_substr($search, 0, 100));

        if ($type === 'child') {
            $query = FamilyMember::query()
                ->with('family:id,center_id,external_family_id,manual_reference,head_name')
                ->where('status', 'active')
                ->whereBetween('age', [0, 12])
                ->whereHas('family', fn (Builder $q) => $q->where('center_id', $centerId)->where('status', 'active'));
            if ($search !== '') {
                $query->where(function (Builder $q) use ($search): void {
                    $q->where('name', 'ilike', '%'.$search.'%')
                        ->orWhereHas('family', function (Builder $family) use ($search): void {
                            $family->where('head_name', 'ilike', '%'.$search.'%')
                                ->orWhere('external_family_id', 'ilike', '%'.$search.'%')
                                ->orWhere('manual_reference', 'ilike', '%'.$search.'%');
                        });
                });
            }
            return $query->orderBy('name')->limit(50)->get()->map(fn (FamilyMember $member) => [
                'id' => $member->id,
                'center_id' => $member->family?->center_id,
                'name' => $member->name,
                'gender' => $member->gender,
                'age' => $member->age,
                'family_reference' => $member->family?->external_family_id ?? $member->family?->manual_reference,
                'family_head' => $member->family?->head_name,
            ])->values()->all();
        }

        if ($type === 'sanchalak') {
            $query = Karyakar::query()->where('center_id', $centerId)->where('status', 'approved')
                ->whereNotNull('user_id')
                ->whereHas('user', fn (Builder $q) => $q->where('status', 'active')->whereHas('roles', fn (Builder $roles) => $roles->where('roles.slug', 'sanchalak')));
            if ($search !== '') {
                $query->where(fn (Builder $q) => $q->where('full_name', 'ilike', '%'.$search.'%')->orWhere('karyakar_reference', 'ilike', '%'.$search.'%'));
            }
            return $query->orderBy('full_name')->limit(50)->get(['id', 'center_id', 'full_name', 'gender', 'category', 'user_id', 'karyakar_reference'])
                ->map(fn (Karyakar $k) => [
                    'id' => $k->id, 'center_id' => $k->center_id, 'full_name' => $k->full_name,
                    'gender' => $k->gender, 'category' => $k->category, 'user_id' => $k->user_id,
                    'karyakar_reference' => $k->karyakar_reference,
                ])->values()->all();
        }

        if (in_array($type, ['nirdeshak', 'nirikshak'], true)) {
            $query = User::query()->with('roles')->where('status', 'active')->whereHas('roles', function (Builder $q) use ($type, $centerId): void {
                $q->where('roles.slug', $type)->where('user_roles.center_id', $centerId);
            });
            if ($search !== '') {
                $query->where(fn (Builder $q) => $q->where('name', 'ilike', '%'.$search.'%')->orWhere('email', 'ilike', '%'.$search.'%'));
            }
            return $query->orderBy('name')->limit(50)->get(['id', 'name', 'email'])->map(fn (User $person) => [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
            ])->values()->all();
        }

        abort(422, 'Unsupported Bal Pravruti option search type.');
    }

    public function completionOptions(User $user): array
    {
        $groups = $this->groupQuery($user, ['status' => 'active'])->with(['center', 'society'])->orderBy('group_code')->limit(500)->get();
        $centerIds = $groups->pluck('center_id')->unique();
        return [
            'groups' => $groups,
            'societies' => Society::query()->whereIn('center_id', $centerIds)->where('status', 'active')->orderBy('name')->limit(2000)->get(['id', 'center_id', 'name']),
        ];
    }

    public function reportQuery(User $user, array $filters, Builder|Collection|array|null $groupIds = null): Builder
    {
        $groupIds ??= $this->groupQuery($user, $filters)->select('bal_groups.id');
        $query = BalCompletionReport::query()->whereIn('bal_group_id', $groupIds);
        if ($filters['center_id']) $query->where('center_id', $filters['center_id']);
        if ($filters['date_from']) $query->whereDate('completion_date', '>=', $filters['date_from']);
        if ($filters['date_to']) $query->whereDate('completion_date', '<=', $filters['date_to']);
        return $query;
    }

    private function centerPerformance(User $user, array $filters): array
    {
        $centerIds = $filters['center_id'] ? collect([$filters['center_id']]) : $this->allowedCenterIds($user);
        return Center::query()->with('zone')->whereIn('id', $centerIds)->orderBy('name')->get()->map(function (Center $center) use ($user, $filters): array {
            $groupQuery = $this->groupQuery($user, $filters)->where('bal_groups.center_id', $center->id);
            $groupIdSubquery = (clone $groupQuery)->select('bal_groups.id');
            $reports = $this->reportQuery($user, $filters, clone $groupIdSubquery);
            $visited = (int) (clone $reports)->sum('families_visited');
            $completed = (int) (clone $reports)->sum('families_completed');
            return [
                'center_id' => $center->id,
                'center' => $center->name,
                'center_code' => $center->code,
                'zone_id' => $center->zone_id,
                'zone' => $center->zone?->name,
                'groups' => (clone $groupQuery)->count(),
                'children' => BalGroupChild::query()->whereIn('bal_group_id', clone $groupIdSubquery)->where('status', 'active')->count(),
                'reports' => (clone $reports)->count(),
                'families_visited' => $visited,
                'families_completed' => $completed,
                'completion_rate' => $visited > 0 ? round(($completed / $visited) * 100, 2) : 0.0,
            ];
        })->values()->all();
    }

    private function zonePerformance(array $centerPerformance): array
    {
        return collect($centerPerformance)->groupBy(fn (array $row) => (string) ($row['zone_id'] ?? 'none'))->map(function (Collection $rows): array {
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

    private function groupPerformance(User $user, array $filters): array
    {
        $groups = $this->groupQuery($user, $filters)
            ->with(['center:id,name', 'sanchalak:id,full_name'])
            ->withCount(['children as active_children_count' => fn (Builder $q) => $q->where('status', 'active')])
            ->orderBy('group_code')
            ->limit(300)
            ->get();
        $groupIds = $groups->pluck('id');
        if ($groupIds->isEmpty()) {
            return [];
        }

        $aggregateQuery = BalCompletionReport::query()->whereIn('bal_group_id', $groupIds);
        if ($filters['date_from']) $aggregateQuery->whereDate('completion_date', '>=', $filters['date_from']);
        if ($filters['date_to']) $aggregateQuery->whereDate('completion_date', '<=', $filters['date_to']);
        $aggregates = $aggregateQuery
            ->selectRaw('bal_group_id, COUNT(*) as reports, COALESCE(SUM(families_visited),0) as visited, COALESCE(SUM(families_completed),0) as completed, MAX(completion_date) as last_completion_date')
            ->groupBy('bal_group_id')->get()->keyBy('bal_group_id');

        return $groups->map(function (BalGroup $group) use ($aggregates): array {
            $row = $aggregates->get($group->id);
            $visited = (int) ($row?->visited ?? 0);
            $completed = (int) ($row?->completed ?? 0);
            return [
                'group_id' => $group->id,
                'group_code' => $group->group_code,
                'center' => $group->center?->name,
                'sanchalak' => $group->sanchalak?->full_name,
                'children' => (int) ($group->active_children_count ?? 0),
                'reports' => (int) ($row?->reports ?? 0),
                'families_visited' => $visited,
                'families_completed' => $completed,
                'completion_rate' => $visited > 0 ? round(($completed / $visited) * 100, 2) : 0.0,
                'last_completion_date' => $row?->last_completion_date ? (string) $row->last_completion_date : null,
            ];
        })->values()->all();
    }

    private function childGenderDistribution(Builder|Collection|array $groupIds): array
    {
        $rows = BalGroupChild::query()->whereIn('bal_group_id', $groupIds)->where('bal_group_children.status', 'active')
            ->join('family_members', 'family_members.id', '=', 'bal_group_children.family_member_id')
            ->selectRaw('family_members.gender as gender, COUNT(*) as total')->groupBy('family_members.gender')->pluck('total', 'gender');
        return [
            ['label' => 'Bal (Male)', 'key' => 'male', 'value' => (int) ($rows['male'] ?? 0)],
            ['label' => 'Balika (Female)', 'key' => 'female', 'value' => (int) ($rows['female'] ?? 0)],
        ];
    }

    private function sanchalakCategoryDistribution(Builder $groupQuery): array
    {
        return (clone $groupQuery)
            ->join('karyakars', 'karyakars.id', '=', 'bal_groups.sanchalak_karyakar_id')
            ->selectRaw("COALESCE(karyakars.category, 'Uncategorized') as label, COUNT(*) as value")
            ->groupBy('karyakars.category')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row) => ['label' => (string) $row->label, 'value' => (int) $row->value])
            ->values()->all();
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
