<?php

namespace App\Http\Controllers\Assignments;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\Karyakar;
use App\Models\SamparkArea;
use App\Models\SankalpGroup;
use App\Models\Society;
use App\Services\Assignments\AreaAssignmentService;
use App\Services\OrganizationalScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AreaAssignmentController extends Controller
{
    public function index(Request $request, OrganizationalScope $scope): Response
    {
        $centerIds = $scope->centers($request->user())->pluck('id');
        return Inertia::render('assignments/areas', [
            'centers' => $scope->centers($request->user())->orderBy('name')->get(['id', 'name', 'code']),
            'areas' => SamparkArea::query()->whereIn('center_id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'center_id', 'name']),
            'societies' => Society::query()->whereIn('center_id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'center_id', 'sampark_area_id', 'name']),
            'groups' => SankalpGroup::query()->whereIn('center_id', $centerIds)->with(['area:id,name', 'society:id,name'])->orderBy('group_code')->get(['id', 'center_id', 'group_code', 'sampark_area_id', 'society_id', 'status']),
            'karyakars' => Karyakar::query()->whereIn('center_id', $centerIds)->where('status', 'approved')->with(['area:id,name', 'society:id,name'])->orderBy('full_name')->limit(1000)->get(['id', 'center_id', 'full_name', 'karyakar_reference', 'sampark_area_id', 'society_id']),
            'families' => Family::query()->whereIn('center_id', $centerIds)->where('status', 'active')->with(['area:id,name', 'society:id,name'])->orderBy('head_name')->limit(1000)->get(['id', 'center_id', 'external_family_id', 'manual_reference', 'head_name', 'sampark_area_id', 'society_id']),
        ]);
    }

    public function store(Request $request, OrganizationalScope $scope, AreaAssignmentService $service): RedirectResponse
    {
        $allowedCenters = $scope->centers($request->user())->pluck('id')->all();
        $data = $request->validate([
            'record_type' => ['required', Rule::in(['group', 'karyakar', 'family'])],
            'record_id' => ['required', 'integer'],
            'sampark_area_id' => ['required', 'integer', 'exists:sampark_areas,id'],
            'society_id' => ['nullable', 'integer', 'exists:societies,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $model = match ($data['record_type']) {
            'group' => SankalpGroup::query()->findOrFail($data['record_id']),
            'karyakar' => Karyakar::query()->findOrFail($data['record_id']),
            'family' => Family::query()->findOrFail($data['record_id']),
        };
        abort_unless(in_array($model->center_id, $allowedCenters, true), 403);
        $service->assign($model, (int) $data['sampark_area_id'], $data['society_id'] ? (int) $data['society_id'] : null, $data['reason']);
        return back()->with('success', 'Sampark Area / Society assignment updated and audited.');
    }
}
