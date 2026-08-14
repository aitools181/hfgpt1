<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use App\Services\Support\SupportScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TestimonialController extends Controller
{
    public function index(Request $request, SupportScopeService $scope): Response
    {
        $user = $request->user();
        $query = Testimonial::query()->with(['center:id,name,code', 'submitter:id,name'])->orderByDesc('id');
        $scope->applyGlobalOrCenterScope($query, $user);
        if (! $user->hasPermission('manage_testimonials')) {
            $query->where(function ($q) use ($user): void {
                $q->where('status', 'published')->orWhere('submitted_by', $user->id);
            });
        }
        return Inertia::render('support/testimonials', [
            'testimonials' => $query->get(),
            'canManage' => $user->hasPermission('manage_testimonials'),
            'canSubmit' => $user->hasPermission('submit_testimonial'),
        ]);
    }

    public function store(Request $request, SupportScopeService $scope): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
        ]);
        Testimonial::query()->create([
            ...$data,
            'center_id' => $scope->primaryCenterId($request->user()),
            'submitted_by' => $request->user()->id,
            'status' => 'pending',
        ]);
        return back()->with('success', 'Feedback submitted for review.');
    }

    public function review(Request $request, Testimonial $testimonial, SupportScopeService $scope): RedirectResponse
    {
        $centerId = $testimonial->center_id ? (int) $testimonial->center_id : null;
        if ($centerId === null) {
            abort_unless($request->user()->hasRole('super_admin') || $request->user()->hasRole('bn_karyalay_admin'), 403, 'Organization-level feedback review requires Karyalay administration.');
        }
        $scope->assertCenterAccess($request->user(), $centerId);
        $data = $request->validate([
            'status' => ['required', 'in:published,rejected,pending'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $testimonial->update([
            ...$data,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        return back()->with('success', 'Feedback review saved.');
    }
}
