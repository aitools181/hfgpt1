<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\FamilyTimeCompletion;
use App\Models\FamilyTimeSchedule;
use App\Services\Support\SupportScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FamilyTimeController extends Controller
{
    public function index(Request $request, SupportScopeService $scope): Response
    {
        $user = $request->user();
        $scheduleQuery = FamilyTimeSchedule::query()->with('center:id,name,code')->withCount('completions')->orderByDesc('starts_at');
        $scope->applyGlobalOrCenterScope($scheduleQuery, $user);
        if (! $user->hasPermission('manage_family_time')) {
            $scheduleQuery->where('status', 'active');
        }
        $schedules = $scheduleQuery->limit(500)->get();

        $completionQuery = FamilyTimeCompletion::query()->with(['schedule:id,title,starts_at', 'user:id,name', 'center:id,name,code'])->orderByDesc('completed_on')->orderByDesc('id');
        if (! $user->hasPermission('manage_family_time')) {
            $completionQuery->where('user_id', $user->id);
        } else {
            $centerIds = $scope->visibleCenterIds($user);
            if (! ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin'))) {
                $completionQuery->where(function ($q) use ($centerIds, $user): void {
                    $q->whereIn('center_id', $centerIds)->orWhere('user_id', $user->id);
                });
            }
        }

        return Inertia::render('support/family-time', [
            'schedules' => $schedules,
            'completions' => $completionQuery->limit(200)->get(),
            'centers' => $user->hasPermission('manage_family_time') ? $this->centers($user, $scope) : [],
            'canManage' => $user->hasPermission('manage_family_time'),
            'canComplete' => $user->hasPermission('record_family_time'),
        ]);
    }

    public function storeSchedule(Request $request, SupportScopeService $scope): RedirectResponse
    {
        $data = $request->validate([
            'center_id' => ['nullable', 'integer', 'exists:centers,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'audience' => ['required', 'in:all,main,bal'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['required', 'in:active,draft,archived'],
        ]);
        $user = $request->user();
        $centerId = isset($data['center_id']) ? (int) $data['center_id'] : null;
        if ($centerId === null && ! ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin'))) {
            abort(422, 'Center is required when creating a Family Time schedule outside Karyalay administration.');
        }
        $scope->assertCenterAccess($user, $centerId);
        FamilyTimeSchedule::query()->create([...$data, 'created_by' => $user->id]);
        return back()->with('success', 'Family Time schedule created.');
    }

    public function complete(Request $request, FamilyTimeSchedule $schedule, SupportScopeService $scope): RedirectResponse
    {
        if ($schedule->center_id) {
            $scope->assertCenterAccess($request->user(), (int) $schedule->center_id);
        }
        abort_unless($schedule->status === 'active', 422, 'Only active Family Time schedules can be completed.');
        $data = $request->validate(['completed_on' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:2000']]);
        FamilyTimeCompletion::query()->updateOrCreate(
            ['family_time_schedule_id' => $schedule->id, 'user_id' => $request->user()->id, 'completed_on' => $data['completed_on']],
            ['center_id' => $scope->primaryCenterId($request->user()) ?? $schedule->center_id, 'note' => $data['note'] ?? null]
        );
        return back()->with('success', 'Family Time completion recorded.');
    }

    private function centers($user, SupportScopeService $scope): array
    {
        $ids = $scope->visibleCenterIds($user);
        return Center::query()->when(! ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin')), fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')->get(['id', 'name', 'code'])->all();
    }
}
