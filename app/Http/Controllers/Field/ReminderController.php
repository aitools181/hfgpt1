<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Models\InactivityEvent;
use App\Models\Karyakar;
use App\Services\OrganizationalScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class ReminderController extends Controller
{
    public function __invoke(Request $request, OrganizationalScope $scope): Response
    {
        $user = $request->user();

        // A previous deployment could contain migration history without the Phase 3
        // operational table. v1.0.3 repairs it during bootstrap; keep this page fail-soft
        // so an administrator sees an actionable message instead of a generic 500.
        if (! Schema::hasTable('inactivity_events')) {
            return Inertia::render('field/reminders', [
                'events' => [
                    'data' => [],
                    'links' => [],
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 30,
                    'total' => 0,
                ],
                'filters' => $request->only(['event_type', 'status', 'center_id', 'group_id', 'karyakar_id']),
                'isOwnView' => $user->hasRole('karyakar'),
                'systemWarning' => 'Reminder/Alert storage is being repaired. Redeploy v1.0.3 so the repair migration can run, then refresh this page.',
            ]);
        }

        $query = InactivityEvent::query()->with([
            'center:id,name,code',
            'group:id,group_code',
            'karyakar:id,full_name,karyakar_reference,mobile,user_id',
            'target:id,name,target_quantity,completed_quantity,status',
        ])->whereHas('center')->whereHas('group')->whereHas('karyakar');

        if ($user->hasRole('karyakar')) {
            $linked = Karyakar::query()->where('user_id', $user->id)->where('status', 'approved')->first();
            abort_unless($linked, 403, 'This portal user is not linked to an approved Sankalp Karyakar.');
            $query->where('karyakar_id', $linked->id);
        } elseif ($user->hasRole('super_admin') || $user->hasRole('bn_karyalay_admin') || $user->hasRole('zonal_admin') || $user->hasRole('center_admin') || $user->hasRole('computer_op')) {
            $query->whereIn('center_id', $scope->centers($user)->pluck('id'));
        } else {
            abort(403);
        }

        foreach (['event_type', 'status', 'center_id', 'group_id', 'karyakar_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return Inertia::render('field/reminders', [
            'events' => $query->latest('triggered_at')->paginate(30)->withQueryString(),
            'filters' => $request->only(['event_type', 'status', 'center_id', 'group_id', 'karyakar_id']),
            'isOwnView' => $user->hasRole('karyakar'),
            'systemWarning' => null,
        ]);
    }
}
