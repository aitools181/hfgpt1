<?php

namespace App\Http\Controllers\Bal;

use App\Http\Controllers\Controller;
use App\Models\BalCompletionReport;
use App\Models\BalGroup;
use App\Models\Family;
use App\Services\Bal\BalPravrutiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompletionController extends Controller
{
    public function index(Request $request, BalPravrutiService $service): Response
    {
        abort_unless($request->user()->hasRole('sanchalak'), 403, 'Only Sanchalak users submit Bal Pravruti completion reports.');
        $filters = $service->filters($request->user(), $request->only(['date_from', 'date_to']));
        $groupIds = $service->groupQuery($request->user())->pluck('id');
        $reports = $service->reportQuery($request->user(), $filters, $groupIds)
            ->with(['group:id,group_code,center_id', 'center:id,name,code', 'society:id,name', 'family:id,external_family_id,manual_reference,head_name', 'submittedBy:id,name'])
            ->orderByDesc('completion_date')->orderByDesc('id')->limit(250)->get();

        return Inertia::render('bal/completions', [
            'reports' => $reports,
            'options' => $service->completionOptions($request->user()),
        ]);
    }

    public function searchFamilies(Request $request, BalGroup $group): JsonResponse
    {
        abort_unless($request->user()->hasRole('sanchalak'), 403, 'Only Sanchalak users can search Bal Pravruti Families.');
        abort_unless((int) $group->sanchalak_user_id === (int) $request->user()->id, 403, 'This Bal Group is outside your assigned scope.');
        abort_unless($group->status === 'active', 422, 'Family search is only available for an active Bal Group.');

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'society_id' => ['nullable', 'integer'],
        ]);
        $search = trim((string) ($data['q'] ?? ''));

        $query = Family::query()->where('center_id', $group->center_id)->where('status', 'active');
        if (! empty($data['society_id'])) {
            $query->where('society_id', (int) $data['society_id']);
        }
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);
            $query->where(function ($q) use ($escaped): void {
                $q->where('head_name', 'ilike', "%{$escaped}%")
                    ->orWhere('external_family_id', 'ilike', "%{$escaped}%")
                    ->orWhere('manual_reference', 'ilike', "%{$escaped}%");
            });
        }

        return response()->json([
            'families' => $query->orderBy('head_name')->limit(30)->get([
                'id', 'center_id', 'society_id', 'external_family_id', 'manual_reference', 'head_name',
            ]),
        ]);
    }

    public function store(Request $request, BalGroup $group, BalPravrutiService $service): RedirectResponse
    {
        abort_unless($request->user()->hasRole('sanchalak'), 403, 'Only Sanchalak users submit Bal Pravruti completion reports.');
        $data = $request->validate([
            'society_id' => ['required', 'integer', 'exists:societies,id'],
            'family_id' => ['nullable', 'integer', 'exists:families,id'],
            'families_visited' => ['required', 'integer', 'min:1', 'max:10000'],
            'families_completed' => ['required', 'integer', 'min:0', 'max:10000'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'family_name' => ['nullable', 'string', 'max:255'],
            'family_details' => ['required', 'string', 'max:5000'],
            'completion_date' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $report = $service->submitCompletion($request->user(), $group, $data);
        return back()->with('success', "Bal Pravruti completion report #{$report->id} submitted successfully.");
    }
}
