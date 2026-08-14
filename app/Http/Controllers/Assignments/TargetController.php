<?php

namespace App\Http\Controllers\Assignments;

use App\Http\Controllers\Controller;
use App\Models\Karyakar;
use App\Models\SamparkArea;
use App\Models\SankalpGroup;
use App\Models\Society;
use App\Models\Target;
use App\Services\Assignments\TargetService;
use App\Services\OrganizationalScope;
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
            'groups' => SankalpGroup::query()->whereIn('center_id', $centerIds)->where('status', 'active')->whereNotNull('sampark_area_id')->orderBy('group_code')->get(['id', 'center_id', 'group_code', 'status']),
            'karyakars' => Karyakar::query()->whereIn('center_id', $centerIds)->where('status', 'approved')->orderBy('full_name')->get(['id', 'center_id', 'full_name', 'karyakar_reference']),
            'groupKaryakars' => \App\Models\GroupKaryakar::query()->where('status', 'active')->whereHas('group', fn ($q) => $q->whereIn('center_id', $centerIds))->get(['group_id', 'karyakar_id']),
            'areas' => SamparkArea::query()->whereIn('center_id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'center_id', 'name']),
            'societies' => Society::query()->whereIn('center_id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'center_id', 'sampark_area_id', 'name']),
            'filters' => $request->only(['center_id', 'status']),
        ]);
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
