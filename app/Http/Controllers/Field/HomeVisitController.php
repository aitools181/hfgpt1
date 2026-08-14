<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Models\GroupFamilyAssignment;
use App\Services\Field\HomeVisitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeVisitController extends Controller
{
    public function store(Request $request, GroupFamilyAssignment $assignment, HomeVisitService $service): RedirectResponse
    {
        $assignment->loadMissing('group');
        abort_unless($request->user()->canAccessCenterId($assignment->group->center_id), 403);

        $data = $request->validate([
            'target_id' => ['nullable', 'integer', 'exists:targets,id'],
            'completion_note' => ['nullable', 'string', 'max:2000'],
            'karyakar_id' => ['nullable', 'integer', 'exists:karyakars,id'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $service->complete($assignment, $request->user(), $data);
        $message = 'Home Visit completed and target progress updated.';
        if ($result['new_badges']) {
            $message .= ' New badge milestone: '.implode(', ', $result['new_badges']).' families.';
        }

        return back()
            ->with('success', $message)
            ->with('completion_report', $result['completion_report'])
            ->with('new_badges', $result['new_badges']);
    }
}
