<?php

namespace App\Http\Controllers\Assignments;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\GroupFamilyAssignment;
use App\Models\Karyakar;
use App\Models\RemainingFamilyReport;
use App\Models\SankalpGroup;
use App\Models\SamparkArea;
use App\Models\Society;
use App\Services\Assignments\GroupAssignmentService;
use App\Services\Assignments\GroupRules;
use App\Services\OrganizationalScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    public function index(Request $request, OrganizationalScope $scope): Response
    {
        $centerIds = $scope->centers($request->user())->pluck('id');
        $query = SankalpGroup::query()->with(['center:id,name,code', 'area:id,name', 'society:id,name'])
            ->withCount([
                'karyakarAssignments as active_karyakars_count' => fn ($q) => $q->where('status', 'active'),
                'familyAssignments as active_families_count' => fn ($q) => $q->where('status', 'active'),
                'familyAssignments as fixed_families_count' => fn ($q) => $q->where('status', 'active')->where('assignment_type', 'fixed'),
                'familyAssignments as remaining_families_count' => fn ($q) => $q->where('status', 'active')->where('assignment_type', 'remaining'),
            ])->whereIn('center_id', $centerIds);

        if (! $this->canManageGroup($request->user())) {
            $linkedKaryakarId = Karyakar::query()->where('user_id', $request->user()->id)->where('status', 'approved')->value('id');
            $query->whereHas('karyakarAssignments', fn ($q) => $q->where('status', 'active')->where('karyakar_id', $linkedKaryakarId ?: 0));
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->string('search'));
            $query->where('group_code', 'ilike', "%{$term}%");
        }
        foreach (['center_id', 'group_type', 'status'] as $filter) {
            if ($request->filled($filter)) $query->where($filter, $request->input($filter));
        }

        return Inertia::render('assignments/groups', [
            'groups' => $query->latest()->paginate(25)->withQueryString(),
            'centers' => $scope->centers($request->user())->orderBy('name')->get(['id', 'name', 'code']),
            'karyakars' => [],
            'groupTypes' => GroupRules::TYPES,
            'canCreate' => $request->user()->hasPermission('create_group'),
            'filters' => $request->only(['search', 'center_id', 'group_type', 'status']),
        ]);
    }

    public function searchKaryakars(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('create_group'), 403);
        $data = $request->validate([
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        abort_unless($request->user()->canAccessCenterId((int) $data['center_id']), 403, 'Center is outside your permitted scope.');
        $search = trim((string) ($data['q'] ?? ''));
        $query = Karyakar::query()->where('center_id', (int) $data['center_id'])->where('status', 'approved');
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('full_name', 'ilike', '%'.$search.'%')->orWhere('karyakar_reference', 'ilike', '%'.$search.'%'));
        }
        return response()->json([
            'results' => $query->orderBy('full_name')->limit(75)->get(['id', 'center_id', 'full_name', 'gender', 'category', 'karyakar_reference']),
        ]);
    }

    public function searchEligibleFamilies(Request $request, SankalpGroup $group): JsonResponse
    {
        $this->authorizeView($request, $group);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $canAdminAssign = $request->user()->hasPermission('manage_fixed_families') || $request->user()->hasPermission('assign_transfer_families');
        $linkedKaryakar = Karyakar::query()->where('user_id', $request->user()->id)->where('status', 'approved')->first();
        $canSelectRemaining = $group->status === 'draft' && $linkedKaryakar
            && $group->karyakarAssignments()->where('status', 'active')->where('karyakar_id', $linkedKaryakar->id)->exists();
        abort_unless($canAdminAssign || $canSelectRemaining, 403, 'You cannot select Families for this Group.');

        $query = Family::query()->where('center_id', $group->center_id)->where('status', 'active')
            ->whereDoesntHave('groupAssignments', fn ($q) => $q->where('status', 'active'));
        if (! $canAdminAssign) {
            if ($group->society_id) {
                $query->where('society_id', $group->society_id);
            } elseif ($group->sampark_area_id) {
                $query->where('sampark_area_id', $group->sampark_area_id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
        $search = trim((string) ($data['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('head_name', 'ilike', '%'.$search.'%')
                    ->orWhere('external_family_id', 'ilike', '%'.$search.'%')
                    ->orWhere('manual_reference', 'ilike', '%'.$search.'%');
            });
        }

        return response()->json([
            'results' => $query->orderBy('head_name')->limit(75)->get(['id', 'external_family_id', 'manual_reference', 'head_name', 'head_mobile']),
        ]);
    }

    public function store(Request $request, OrganizationalScope $scope, GroupAssignmentService $service): RedirectResponse
    {
        $allowedCenters = $scope->centers($request->user())->pluck('id')->all();
        $data = $request->validate([
            'center_id' => ['required', Rule::in($allowedCenters)],
            'group_type' => ['required', Rule::in(GroupRules::TYPES)],
            'karyakar_ids' => ['required', 'array', 'size:2'],
            'karyakar_ids.*' => ['required', 'integer', 'distinct', 'exists:karyakars,id'],
        ]);
        $group = $service->createGroup((int) $data['center_id'], $data['karyakar_ids'], $data['group_type'], $request->user());
        return redirect()->route('groups.show', $group)->with('success', "Group {$group->group_code} created with exactly 2 approved Karyakars.");
    }

    public function show(Request $request, SankalpGroup $group): Response
    {
        $this->authorizeView($request, $group);
        $group->load([
            'center:id,name,code,zone_id', 'area:id,name', 'society:id,name',
            'karyakarAssignments' => fn ($q) => $q->where('status', 'active')->orderBy('position')->with('karyakar:id,center_id,full_name,gender,category,mobile,karyakar_reference,status,user_id'),
            'familyAssignments' => fn ($q) => $q->where('status', 'active')->orderBy('slot_number')->with(['family:id,center_id,external_family_id,manual_reference,head_name,head_mobile,sampark_area_id,society_id,status', 'homeVisit:id,group_family_assignment_id,karyakar_id,completed_at,is_admin_override']),
            'remainingFamilyReports' => fn ($q) => $q->latest('reported_at')->with(['family:id,center_id,manual_reference,head_name,head_mobile,address,city_village,status', 'karyakar:id,full_name,karyakar_reference']),
        ]);

        $activeAssignments = $group->familyAssignments;
        $canManageFixed = $request->user()->hasPermission('manage_fixed_families');
        $canTransferFamilies = $request->user()->hasPermission('assign_transfer_families');
        $canAssignArea = $request->user()->hasPermission('assign_area_society');
        $canAdminAssign = $canManageFixed || $canTransferFamilies;
        $linkedKaryakar = Karyakar::query()->where('user_id', $request->user()->id)->where('status', 'approved')->first();
        $canSelectRemaining = $group->status === 'draft' && $linkedKaryakar && $group->karyakarAssignments->contains(fn ($a) => $a->karyakar_id === $linkedKaryakar->id);

        return Inertia::render('assignments/group-detail', [
            'group' => $group,
            'composition' => [
                'total' => $activeAssignments->count(),
                'fixed' => $activeAssignments->where('assignment_type', 'fixed')->count(),
                'remaining' => $activeAssignments->where('assignment_type', 'remaining')->count(),
                'completed' => $activeAssignments->filter(fn ($assignment) => $assignment->homeVisit !== null)->count(),
                'pending' => $activeAssignments->filter(fn ($assignment) => $assignment->homeVisit === null)->count(),
                'slotsLeft' => max(0, 10 - $activeAssignments->count()),
                'canActivate' => $activeAssignments->count() === 10 && in_array($activeAssignments->where('assignment_type', 'fixed')->count(), [5, 6], true) && in_array($activeAssignments->where('assignment_type', 'remaining')->count(), [4, 5], true),
            ],
            'eligibleFamilies' => [],
            'transferGroups' => SankalpGroup::query()->where('center_id', $group->center_id)->where('id', '!=', $group->id)->where('status', 'draft')
                ->withCount(['familyAssignments as active_families_count' => fn ($q) => $q->where('status', 'active')])
                ->orderBy('group_code')->get(['id', 'group_code', 'status']),
            'areas' => SamparkArea::query()->where('center_id', $group->center_id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'societies' => Society::query()->where('center_id', $group->center_id)->where('status', 'active')->orderBy('name')->get(['id', 'sampark_area_id', 'name']),
            'canAdminAssign' => $canAdminAssign,
            'canManageFixed' => $canManageFixed,
            'canTransferFamilies' => $canTransferFamilies,
            'canAssignArea' => $canAssignArea,
            'canActivate' => $request->user()->hasPermission('create_group'),
            'canSelectRemaining' => (bool) $canSelectRemaining,
        ]);
    }

    public function assignFamily(Request $request, SankalpGroup $group, GroupAssignmentService $service): RedirectResponse
    {
        abort_unless($request->user()->canAccessCenterId($group->center_id), 403);
        $data = $request->validate([
            'family_id' => ['required', 'integer', 'exists:families,id'],
            'assignment_type' => ['required', Rule::in(['fixed', 'remaining'])],
            'change_note' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($data['assignment_type'] === 'fixed') {
            abort_unless($request->user()->hasPermission('manage_fixed_families'), 403, 'Managing Fixed/Locked Families requires the dedicated permission.');
        } else {
            abort_unless($request->user()->hasPermission('assign_transfer_families'), 403, 'Assigning Remaining Families requires the Family assignment/transfer permission.');
        }
        $service->assignFamily($group, (int) $data['family_id'], $data['assignment_type'], $request->user(), 'admin', $data['change_note'] ?? null);
        return back()->with('success', 'Sankalp Family assigned to the Group.');
    }

    public function selectRemainingFamily(Request $request, SankalpGroup $group, GroupAssignmentService $service): RedirectResponse
    {
        $linked = Karyakar::query()->where('user_id', $request->user()->id)->where('center_id', $group->center_id)->where('status', 'approved')->first();
        abort_unless($linked && $group->karyakarAssignments()->where('karyakar_id', $linked->id)->where('status', 'active')->exists(), 403, 'Only a Karyakar assigned to this Group may select a Remaining Family.');
        $data = $request->validate([
            'family_id' => ['required', 'integer', 'exists:families,id'],
            'change_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->assignFamily($group, (int) $data['family_id'], 'remaining', $request->user(), 'karyakar', $data['change_note'] ?? 'Selected by assigned Sankalp Karyakar and reported to Center Admin.');
        return back()->with('success', 'Remaining Sankalp Family selected and recorded for Center Admin review.');
    }

    public function reportNewRemainingFamily(Request $request, SankalpGroup $group, GroupAssignmentService $service): RedirectResponse
    {
        $linked = Karyakar::query()->where('user_id', $request->user()->id)->where('center_id', $group->center_id)->where('status', 'approved')->first();
        abort_unless($linked && $group->karyakarAssignments()->where('karyakar_id', $linked->id)->where('status', 'active')->exists(), 403, 'Only a Karyakar assigned to this Group may report a new Remaining Family.');
        $data = $request->validate([
            'head_name' => ['required', 'string', 'max:255'],
            'head_mobile' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city_village' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->reportNewRemainingFamily($group, $linked, $data, $request->user());
        return back()->with('success', 'New Remaining Family reported to Center Admin for verification.');
    }

    public function reviewRemainingFamilyReport(Request $request, SankalpGroup $group, RemainingFamilyReport $report, GroupAssignmentService $service): RedirectResponse
    {
        abort_unless($report->group_id === $group->id && $request->user()->canAccessCenterId($group->center_id), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'rejected'])],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->reviewRemainingFamilyReport($report, $data['decision'], $request->user(), $data['review_note'] ?? null);
        return back()->with('success', 'Remaining Family report reviewed.');
    }

    public function activate(Request $request, SankalpGroup $group, GroupAssignmentService $service): RedirectResponse
    {
        abort_unless($request->user()->canAccessCenterId($group->center_id), 403);
        $service->activate($group, $request->user());
        return back()->with('success', 'Group activated with exactly 2 Karyakars and exactly 10 Families.');
    }

    public function transferFamily(Request $request, SankalpGroup $group, GroupFamilyAssignment $assignment, GroupAssignmentService $service): RedirectResponse
    {
        abort_unless($assignment->group_id === $group->id && $request->user()->canAccessCenterId($group->center_id), 403);
        $data = $request->validate([
            'destination_group_id' => ['required', 'integer', 'exists:groups,id'],
            'assignment_type' => ['required', Rule::in(['fixed', 'remaining'])],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $destination = SankalpGroup::query()->findOrFail($data['destination_group_id']);
        abort_unless($request->user()->canAccessCenterId($destination->center_id), 403);
        $service->transferFamily($assignment, $destination, $data['assignment_type'], $request->user(), $data['reason']);
        return back()->with('success', 'Family transferred. The old active assignment was closed and the new assignment is active.');
    }

    private function authorizeView(Request $request, SankalpGroup $group): void
    {
        abort_unless($request->user()->canAccessCenterId($group->center_id), 403);
        if ($this->canManageGroup($request->user())) return;
        $linked = Karyakar::query()->where('user_id', $request->user()->id)->where('status', 'approved')->first();
        abort_unless($linked && $group->karyakarAssignments()->where('karyakar_id', $linked->id)->where('status', 'active')->exists(), 403);
    }

    private function canManageGroup($user): bool
    {
        foreach (['create_group', 'manage_fixed_families', 'assign_transfer_families', 'assign_area_society', 'assign_target'] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }
}
