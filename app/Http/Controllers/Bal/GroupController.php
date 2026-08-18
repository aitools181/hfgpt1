<?php

namespace App\Http\Controllers\Bal;

use App\Http\Controllers\Controller;
use App\Models\BalGroup;
use App\Services\Bal\BalPravrutiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    public function index(Request $request, BalPravrutiService $service): Response
    {
        $groups = $service->groupQuery($request->user())
            ->with(['center:id,name,code', 'area:id,name', 'society:id,name', 'sanchalak:id,full_name,gender,category,mobile', 'supervisors.user:id,name'])
            ->withCount([
                'children as active_children_count' => fn ($q) => $q->where('status', 'active'),
                'completionReports',
            ])
            ->orderBy('group_code')
            ->paginate(100)
            ->withQueryString()
            ->through(fn (BalGroup $group) => [
                'id' => $group->id,
                'group_code' => $group->group_code,
                'status' => $group->status,
                'center' => $group->center,
                'area' => $group->area,
                'society' => $group->society,
                'sanchalak' => $group->sanchalak,
                'children_count' => (int) $group->active_children_count,
                'completion_reports_count' => $group->completion_reports_count,
                'supervisors' => $group->supervisors->where('status', 'active')->map(fn ($s) => ['role_slug' => $s->role_slug, 'user' => $s->user])->values(),
            ]);

        $options = $request->user()->hasPermission('manage_bal_groups') ? $service->creationOptions($request->user()) : null;

        return Inertia::render('bal/groups', [
            'groups' => $groups,
            'options' => $options ? $this->serializeCreationOptions($options) : null,
            'canManage' => $request->user()->hasPermission('manage_bal_groups'),
        ]);
    }

    public function searchOptions(Request $request, BalPravrutiService $service): JsonResponse
    {
        $data = $request->validate([
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'type' => ['required', 'string', 'in:child,sanchalak,nirdeshak,nirikshak'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'results' => $service->searchCreationOptions(
                $request->user(),
                (int) $data['center_id'],
                $data['type'],
                (string) ($data['q'] ?? ''),
            ),
        ]);
    }

    public function store(Request $request, BalPravrutiService $service): RedirectResponse
    {
        $data = $request->validate([
            'center_id' => ['required', 'integer', 'exists:centers,id'],
            'sampark_area_id' => ['nullable', 'integer', 'exists:sampark_areas,id'],
            'society_id' => ['nullable', 'integer', 'exists:societies,id'],
            'sanchalak_karyakar_id' => ['required', 'integer', 'exists:karyakars,id'],
            'child_member_ids' => ['required', 'array', 'size:3'],
            'child_member_ids.*' => ['required', 'integer', 'distinct', 'exists:family_members,id'],
            'nirdeshak_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'nirikshak_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $group = $service->createGroup($request->user(), $data);
        return redirect()->route('bal.groups.show', $group)->with('success', "Bal Pravruti Group {$group->group_code} created with exactly 3 children and 1 Sanchalak.");
    }

    public function show(Request $request, BalGroup $group, BalPravrutiService $service): Response
    {
        $group = $service->groupQuery($request->user())->whereKey($group->id)
            ->with([
                'center:id,name,code', 'area:id,name', 'society:id,name',
                'sanchalak:id,full_name,gender,category,mobile,user_id', 'sanchalakUser:id,name,email',
                'children' => fn ($q) => $q->with('member.family:id,center_id,external_family_id,manual_reference,head_name')->orderBy('position'),
                'supervisors.user:id,name,email',
                'completionReports' => fn ($q) => $q->with(['society:id,name', 'family:id,external_family_id,manual_reference,head_name', 'submittedBy:id,name'])->orderByDesc('completion_date')->orderByDesc('id')->limit(200),
            ])->withCount('completionReports')->firstOrFail();

        return Inertia::render('bal/group-detail', ['group' => $group]);
    }

    private function serializeCreationOptions(array $options): array
    {
        return [
            'centers' => $options['centers'],
            'areas' => $options['areas'],
            'societies' => $options['societies'],
        ];
    }
}
