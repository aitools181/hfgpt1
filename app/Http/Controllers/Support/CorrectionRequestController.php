<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\CorrectionRequest;
use App\Services\Support\SupportScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CorrectionRequestController extends Controller
{
    public function index(Request $request, SupportScopeService $scope): Response
    {
        $user = $request->user();
        $centerIds = $scope->visibleCenterIds($user);
        $query = CorrectionRequest::query()
            ->with(['user:id,name,email', 'center:id,name,code', 'reviewer:id,name'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'under_review' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END")
            ->latest();

        if ($user->hasPermission('manage_correction_requests')) {
            if (! ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin'))) {
                $query->where(function ($q) use ($centerIds, $user): void {
                    if ($centerIds !== []) {
                        $q->whereIn('center_id', $centerIds)->orWhere('user_id', $user->id);
                    } else {
                        $q->where('user_id', $user->id);
                    }
                });
            }
        } else {
            $query->where('user_id', $user->id);
        }

        return Inertia::render('support/corrections', [
            'requests' => $query->limit(300)->get(),
            'centers' => $user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin')
                ? \App\Models\Center::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code'])
                : \App\Models\Center::query()->whereIn('id', $centerIds)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'canManage' => $user->hasPermission('manage_correction_requests'),
            'canUseGlobal' => $user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin'),
        ]);
    }

    public function store(Request $request, SupportScopeService $scope): RedirectResponse
    {
        $user = $request->user();
        $centerIds = $scope->visibleCenterIds($user);
        $canUseGlobal = $user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin');

        $data = $request->validate([
            'center_id' => [$canUseGlobal ? 'nullable' : 'required', 'integer', Rule::in($centerIds)],
            'module' => ['required', Rule::in(['family', 'karyakar', 'group', 'assignment', 'area_society', 'target', 'home_visit', 'bal_pravruti', 'user_access', 'other'])],
            'record_reference' => ['nullable', 'string', 'max:255'],
            'requested_change' => ['required', 'string', 'max:10000'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        CorrectionRequest::query()->create([
            ...$data,
            'user_id' => $user->id,
            'center_id' => $data['center_id'] ?? null,
            'status' => 'pending',
        ]);

        return back()->with('success', 'Correction / change request submitted for review.');
    }

    public function update(Request $request, CorrectionRequest $correctionRequest, SupportScopeService $scope): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('manage_correction_requests'), 403);

        if ($correctionRequest->center_id === null) {
            abort_unless($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin'), 403, 'Organization-level correction requests require Karyalay administration.');
        } else {
            $scope->assertCenterAccess($user, (int) $correctionRequest->center_id);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'under_review', 'approved', 'rejected', 'completed'])],
            'review_note' => ['nullable', 'string', 'max:5000'],
        ]);

        if (in_array($data['status'], ['approved', 'rejected', 'completed'], true) && trim((string) ($data['review_note'] ?? '')) === '') {
            throw ValidationException::withMessages(['review_note' => 'A review note is required for approved, rejected, or completed requests.']);
        }

        $correctionRequest->update([
            ...$data,
            'reviewed_by' => $data['status'] === 'pending' ? null : $user->id,
            'reviewed_at' => $data['status'] === 'pending' ? null : now(),
        ]);

        return back()->with('success', 'Correction / change request updated.');
    }
}
