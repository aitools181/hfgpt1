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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AreaAssignmentController extends Controller
{
    public function index(Request $request, OrganizationalScope $scope): Response
    {
        return Inertia::render('assignments/areas', [
            'centers' => $scope->centers($request->user())->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function searchOptions(Request $request, OrganizationalScope $scope): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['group', 'karyakar', 'family', 'area', 'society'])],
            'center_id' => ['required', 'integer'],
            'area_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $centerId = (int) $data['center_id'];
        abort_unless($scope->centers($request->user())->whereKey($centerId)->exists(), 403, 'Center is outside your permitted scope.');
        $search = trim((string) ($data['q'] ?? ''));

        if ($data['type'] === 'group') {
            $query = SankalpGroup::query()->with(['area:id,name', 'society:id,name'])->where('center_id', $centerId);
            if ($search !== '') $query->where('group_code', 'ilike', '%'.$search.'%');
            return response()->json($query->orderBy('group_code')->limit(75)->get([
                'id', 'center_id', 'group_code', 'sampark_area_id', 'society_id', 'status',
            ])->map(fn (SankalpGroup $group) => [
                'id' => $group->id,
                'center_id' => $group->center_id,
                'label' => $group->group_code,
                'sampark_area_id' => $group->sampark_area_id,
                'society_id' => $group->society_id,
                'area' => $group->area?->only(['id', 'name']),
                'society' => $group->society?->only(['id', 'name']),
            ]));
        }

        if ($data['type'] === 'karyakar') {
            $query = Karyakar::query()->with(['area:id,name', 'society:id,name'])->where('center_id', $centerId)->where('status', 'approved');
            if ($search !== '') {
                $query->where(fn (Builder $q) => $q->where('full_name', 'ilike', '%'.$search.'%')->orWhere('karyakar_reference', 'ilike', '%'.$search.'%'));
            }
            return response()->json($query->orderBy('full_name')->limit(75)->get([
                'id', 'center_id', 'full_name', 'karyakar_reference', 'sampark_area_id', 'society_id',
            ])->map(fn (Karyakar $karyakar) => [
                'id' => $karyakar->id,
                'center_id' => $karyakar->center_id,
                'label' => trim($karyakar->karyakar_reference.' - '.$karyakar->full_name, ' -'),
                'sampark_area_id' => $karyakar->sampark_area_id,
                'society_id' => $karyakar->society_id,
                'area' => $karyakar->area?->only(['id', 'name']),
                'society' => $karyakar->society?->only(['id', 'name']),
            ]));
        }

        if ($data['type'] === 'family') {
            $query = Family::query()->with(['area:id,name', 'society:id,name'])->where('center_id', $centerId)->where('status', 'active');
            if ($search !== '') {
                $query->where(function (Builder $q) use ($search): void {
                    $q->where('head_name', 'ilike', '%'.$search.'%')
                        ->orWhere('external_family_id', 'ilike', '%'.$search.'%')
                        ->orWhere('manual_reference', 'ilike', '%'.$search.'%');
                });
            }
            return response()->json($query->orderBy('head_name')->limit(75)->get([
                'id', 'center_id', 'external_family_id', 'manual_reference', 'head_name', 'sampark_area_id', 'society_id',
            ])->map(fn (Family $family) => [
                'id' => $family->id,
                'center_id' => $family->center_id,
                'label' => trim(($family->external_family_id ?? $family->manual_reference ?? '').' - '.$family->head_name, ' -'),
                'sampark_area_id' => $family->sampark_area_id,
                'society_id' => $family->society_id,
                'area' => $family->area?->only(['id', 'name']),
                'society' => $family->society?->only(['id', 'name']),
            ]));
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
