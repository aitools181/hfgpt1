<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\SharedContent;
use App\Services\Support\SupportScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SharedContentController extends Controller
{
    public function index(Request $request, SupportScopeService $scope): Response
    {
        $user = $request->user();
        $query = SharedContent::query()->with('center:id,name,code')->orderBy('sort_order')->orderByDesc('published_at')->orderByDesc('id');
        $scope->applyGlobalOrCenterScope($query, $user);
        if (! $user->hasPermission('manage_shared_content')) {
            $query->where('status', 'published')->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        }
        if ($request->filled('type')) {
            $query->where('content_type', $request->string('type')->toString());
        }

        return Inertia::render('support/content', [
            'contents' => $query->limit(500)->get(),
            'centers' => $user->hasPermission('manage_shared_content') ? $this->centers($user, $scope) : [],
            'canManage' => $user->hasPermission('manage_shared_content'),
            'types' => ['quote', 'aagna', 'sankalp', 'vachan', 'ashirwad', 'video', 'pdf', 'audio', 'image', 'link', 'motivation'],
            'filters' => ['type' => $request->input('type')],
        ]);
    }

    public function store(Request $request, SupportScopeService $scope): RedirectResponse
    {
        $data = $request->validate([
            'center_id' => ['nullable', 'integer', 'exists:centers,id'],
            'content_type' => ['required', 'in:quote,aagna,sankalp,vachan,ashirwad,video,pdf,audio,image,link,motivation'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'url' => ['nullable', 'url', 'max:1000'],
            'media_file' => ['nullable', 'file', 'max:25600', 'mimes:pdf,mp4,mp3,m4a,wav,jpg,jpeg,png,webp'],
            'audience' => ['required', 'in:all,main,bal'],
            'status' => ['required', 'in:draft,published,archived'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
        ]);
        $centerId = isset($data['center_id']) ? (int) $data['center_id'] : null;
        if ($centerId === null) {
            abort_unless($request->user()->hasRole('super_admin') || $request->user()->hasRole('bn_karyalay_admin'), 403, 'Organization-wide shared content requires Karyalay administration.');
        }
        $scope->assertCenterAccess($request->user(), $centerId);
        $filePath = $request->file('media_file')?->store('shared-content', 'public');
        unset($data['media_file']);
        SharedContent::query()->create([...$data, 'file_path' => $filePath, 'sort_order' => $data['sort_order'] ?? 0, 'published_at' => $data['published_at'] ?? now(), 'created_by' => $request->user()->id]);
        return back()->with('success', 'Shared content saved.');
    }

    public function update(Request $request, SharedContent $content, SupportScopeService $scope): RedirectResponse
    {
        $centerId = $content->center_id ? (int) $content->center_id : null;
        if ($centerId === null) {
            abort_unless($request->user()->hasRole('super_admin') || $request->user()->hasRole('bn_karyalay_admin'), 403, 'Organization-wide shared content requires Karyalay administration.');
        }
        $scope->assertCenterAccess($request->user(), $centerId);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'url' => ['nullable', 'url', 'max:1000'],
            'status' => ['required', 'in:draft,published,archived'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
        ]);
        $content->update($data);
        return back()->with('success', 'Shared content updated.');
    }

    public function destroy(Request $request, SharedContent $content, SupportScopeService $scope): RedirectResponse
    {
        $centerId = $content->center_id ? (int) $content->center_id : null;
        if ($centerId === null) {
            abort_unless($request->user()->hasRole('super_admin') || $request->user()->hasRole('bn_karyalay_admin'), 403, 'Organization-wide shared content requires Karyalay administration.');
        }
        $scope->assertCenterAccess($request->user(), $centerId);
        if ($content->file_path) {
            Storage::disk('public')->delete($content->file_path);
        }
        $content->delete();
        return back()->with('success', 'Shared content removed.');
    }

    private function centers($user, SupportScopeService $scope): array
    {
        $ids = $scope->visibleCenterIds($user);
        return Center::query()->when(! ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin')), fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('name')->get(['id', 'name', 'code'])->all();
    }
}
