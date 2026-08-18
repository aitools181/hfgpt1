<?php

namespace App\Http\Controllers\Assignments;

use App\Http\Controllers\Controller;
use App\Models\GroupKaryakar;
use App\Models\Karyakar;
use App\Models\SamparkArea;
use App\Models\SankalpGroup;
use App\Models\Society;
use App\Models\Target;
use App\Services\Assignments\TargetService;
use App\Services\OrganizationalScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TargetController extends Controller
{
    public function index(Request $request, OrganizationalScope $scope): Response
    {
        $centerIds = $scope->centers($request->user())->pluck('id');
        $query = Target::query()->with(['center:id,name,code', 'group:id,group_code', 'karyakar:id,full_name,karyakar_reference', 'area:id,name', 'society:id,name'])->whereIn('center_id', $centerIds);
        if ($request->filled('center_id')) $query->where('center_id', $request->integer('center_id'));
        if ($request->filled('status')) $query->where('status', $request->input('status'));

        return Inertia::render('assignments/targets', [
            'targets' => $query->latest()->paginate(25)->withQueryString(),
            'centers' => $scope->centers($request->user())->orderBy('name')->get(['id', 'name', 'code']),
            'filters' => $request->only(['center_id', 'status']),
        ]);
    }

    public function searchOptions(Request $request, OrganizationalScope $scope): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['group', 'karyakar', 'area', 'society'])],
            'center_id' => ['required', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'area_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $centerId = (int) $data['center_id'];
        abort_unless($scope->centers($request->user())->whereKey($centerId)->exists(), 403, 'Center is outside your permitted scope.');
        $search = trim((string) ($data['q'] ?? ''));

        if ($data['type'] === 'group') {
            $query = SankalpGroup::query()
                ->where('center_id', $centerId)
                ->where('status', 'active')
                ->whereNotNull('sampark_area_id');
            if ($search !== '') {
                $query->where('group_code', 'ilike', '%'.$search.'%');
            }

            return response()->json($query->orderBy('group_code')->limit(75)->get([
                'id', 'center_id', 'group_code', 'sampark_area_id', 'society_id', 'status',
            ]));
        }

        if ($data['type'] === 'karyakar') {
            abort_unless(! empty($data['group_id']), 422, 'Group is required for Karyakar options.');
            $groupId = (int) $data['group_id'];
            abort_unless(SankalpGroup::query()->whereKey($groupId)->where('center_id', $centerId)->exists(), 422, 'Selected Group is invalid for the Center.');

            $query = Karyakar::query()
                ->where('center_id', $centerId)
                ->where('status', 'approved')
                ->whereIn('id', GroupKaryakar::query()->where('group_id', $groupId)->where('status', 'active')->select('karyakar_id'));
            if ($search !== '') {
                $query->where(fn ($q) => $q->where('full_name', 'ilike', '%'.$search.'%')->orWhere('karyakar_reference', 'ilike', '%'.$search.'%'));
            }

            return response()->json($query->orderBy('full_name')->limit(50)->get(['id', 'center_id', 'full_name', 'karyakar_reference']));
        }

        if ($data['type'] === 'area') {
            $query = SamparkArea::query()->where('center_id', $centerId)->where('status', 'active');
            if ($search !== '') $query->where('name', 'ilike', '%'.$search.'%');
            return response()->json($query->orderBy('name')->limit(100)->get(['id', 'center_id', 'name']));
        }

        $query = Society::query()->where('center_id', $centerId)->where('status', 'active');
        if (! empty($data['area_id'])) $query->where('sampark_area_id', (int) $data['area_id']);
        if ($search !== '') $query->where('name', 'ilike', '%'.$search.'%');
        return response()->json($query->orderBy('name')->limit(100)->get(['id', 'center_id', 'sampark_area_id', 'name']));
    }

    public function store(Request $request, OrganizationalScope $scope, TargetService $service): RedirectResponse
    {
        $allowedCenters = $scope->centers($request->user())->pluck('id')->all();
        $data = $request->validate([
            'center_id' => ['required', Rule::in($allowedCenters)],
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'karyakar_id' => ['nullable', 'integer', 'exists:karyakars,id'],
            'sampark_area_id' => ['required', 'integer', 'exists:sampark_areas,id'],
            'society_id' => ['nullable', 'integer', 'exists:societies,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'target_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $service->create($data, $request->user());
        return back()->with('success', 'Target assigned successfully. Completion will update from recorded Home Visits.');
    }
}
