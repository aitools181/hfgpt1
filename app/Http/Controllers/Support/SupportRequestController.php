<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportRequest;
use App\Services\Support\SupportScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportRequestController extends Controller
{
    public function index(Request $request, SupportScopeService $scope): Response
    {
        $user = $request->user();
        $query = SupportRequest::query()->with(['user:id,name,email', 'center:id,name,code', 'resolver:id,name'])->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END")->orderByDesc('created_at');
        if ($user->hasPermission('manage_support')) {
            if (! ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin'))) {
                $centerIds = $scope->visibleCenterIds($user);
                $query->where(function ($q) use ($centerIds, $user): void {
                    $q->whereIn('center_id', $centerIds)->orWhere('user_id', $user->id);
                });
            }
        } else {
            $query->where('user_id', $user->id);
        }
        return Inertia::render('support/contact', [
            'requests' => $query->limit(300)->get(),
            'canManage' => $user->hasPermission('manage_support'),
        ]);
    }

    public function store(Request $request, SupportScopeService $scope): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'category' => ['required', 'in:general,access,data,technical,content,other'],
            'message' => ['required', 'string', 'max:10000'],
            'priority' => ['required', 'in:low,normal,high'],
        ]);
        SupportRequest::query()->create([
            ...$data,
            'user_id' => $request->user()->id,
            'center_id' => $scope->primaryCenterId($request->user()),
            'status' => 'open',
        ]);
        return back()->with('success', 'Support request submitted.');
    }

    public function update(Request $request, SupportRequest $supportRequest, SupportScopeService $scope): RedirectResponse
    {
        $user = $request->user();
        if ($supportRequest->center_id === null) {
            abort_unless($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin'), 403, 'Organization-level support requests require Karyalay administration.');
        } else {
            $scope->assertCenterAccess($user, (int) $supportRequest->center_id);
        }
        $data = $request->validate([
            'status' => ['required', 'in:open,in_progress,resolved,closed'],
            'response_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $resolved = in_array($data['status'], ['resolved', 'closed'], true);
        $supportRequest->update([
            ...$data,
            'resolved_by' => $resolved ? $user->id : null,
            'resolved_at' => $resolved ? now() : null,
        ]);
        return back()->with('success', 'Support request updated.');
    }
}
