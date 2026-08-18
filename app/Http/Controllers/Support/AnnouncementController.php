<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Center;
use App\Services\Support\SupportScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function index(Request $request, SupportScopeService $scope): Response
    {
        $user = $request->user();
        $query = Announcement::query()->with('center:id,name,code')->orderByDesc('published_at')->orderByDesc('id');
        $scope->applyGlobalOrCenterScope($query, $user);
        if (! $user->hasPermission('manage_announcements')) {
            $query->where('status', 'published')->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }

        return Inertia::render('support/announcements', [
            'announcements' => $query->limit(500)->get(),
            'centers' => $user->hasPermission('manage_announcements') ? $this->centers($user, $scope) : [],
            'canManage' => $user->hasPermission('manage_announcements'),
        ]);
    }

    public function store(Request $request, SupportScopeService $scope): RedirectResponse
    {
        $data = $request->validate([
            'center_id' => ['nullable', 'integer', 'exists:centers,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'audience' => ['required', 'in:all,main,bal'],
            'status' => ['required', 'in:draft,published,archived'],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
        ]);
        $centerId = isset($data['center_id']) ? (int) $data['center_id'] : null;
        if ($centerId === null) {
            abort_unless($request->user()->hasRole('super_admin') || $request->user()->hasRole('bn_karyalay_admin'), 403, 'Organization-wide announcements require Karyalay administration.');
        }
        $scope->assertCenterAccess($request->user(), $centerId);
        Announcement::query()->create([...$data, 'created_by' => $request->user()->id, 'published_at' => $data['published_at'] ?? now()]);
        return back()->with('success', 'Announcement saved.');
    }

    public function update(Request $request, Announcement $announcement, SupportScopeService $scope): RedirectResponse
    {
        $centerId = $announcement->center_id ? (int) $announcement->center_id : null;
        if ($centerId === null) {
            abort_unless($request->user()->hasRole('super_admin') || $request->user()->hasRole('bn_karyalay_admin'), 403, 'Organization-wide announcements require Karyalay administration.');
        }
        $scope->assertCenterAccess($request->user(), $centerId);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'audience' => ['required', 'in:all,main,bal'],
            'status' => ['required', 'in:draft,published,archived'],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
        ]);
        $announcement->update($data);
        return back()->with('success', 'Announcement updated.');
    }

    private function centers($user, SupportScopeService $scope): array
    {
        $ids = $scope->visibleCenterIds($user);
        return Center::query()->when(! ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin')), fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')->get(['id', 'name', 'code'])->all();
    }
}
