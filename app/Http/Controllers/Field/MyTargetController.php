<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Models\InactivityEvent;
use App\Models\Karyakar;
use App\Models\SankalpGroup;
use App\Models\Target;
use App\Services\Field\BadgeService;
use App\Services\Field\TargetProgressService;
use App\Services\OrganizationalScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MyTargetController extends Controller
{
    public function __invoke(Request $request, OrganizationalScope $scope, BadgeService $badges, TargetProgressService $progress): Response
    {
        $user = $request->user();
        $karyakar = Karyakar::query()->where('user_id', $user->id)->where('status', 'approved')->first();
        $adminChoices = collect();

        if ($user->hasRole('super_admin')) {
            $centerIds = $scope->centers($user)->pluck('id');
            $adminChoices = Karyakar::query()->whereIn('center_id', $centerIds)->where('status', 'approved')->orderBy('full_name')->limit(500)->get(['id', 'center_id', 'full_name', 'karyakar_reference']);
            if ($request->filled('karyakar_id')) {
                $karyakar = Karyakar::query()->whereIn('center_id', $centerIds)->where('status', 'approved')->findOrFail((int) $request->integer('karyakar_id'));
            } elseif (! $karyakar && $adminChoices->isNotEmpty()) {
                $karyakar = Karyakar::query()->find($adminChoices->first()->id);
            }
        }

        if (! $karyakar) {
            abort_unless($user->hasRole('super_admin'), 403, 'This portal user is not linked to an approved Sankalp Karyakar.');

            return Inertia::render('field/my-target', [
                'karyakar' => null,
                'groups' => [],
                'targets' => [],
                'badgeSummary' => [
                    'completedFamilies' => 0,
                    'currentMilestone' => null,
                    'nextMilestone' => 3,
                    'remainingToNext' => 3,
                    'earned' => [],
                ],
                'openEvents' => [],
                'adminChoices' => $adminChoices,
                'isAdminPreview' => true,
                'isSuperAdmin' => true,
            ]);
        }

        abort_unless($user->canAccessCenterId($karyakar->center_id), 403);

        $groups = SankalpGroup::query()
            ->where('status', 'active')
            ->whereHas('karyakarAssignments', fn ($q) => $q->where('status', 'active')->where('karyakar_id', $karyakar->id))
            ->with([
                'center:id,name,code,zone_id',
                'center.zone:id,name,code',
                'area:id,name',
                'society:id,name',
                'karyakarAssignments' => fn ($q) => $q->where('status', 'active')->orderBy('position')->with('karyakar:id,full_name,mobile,karyakar_reference,user_id'),
                'familyAssignments' => fn ($q) => $q->where('status', 'active')->orderBy('slot_number')->with([
                    'family:id,external_family_id,manual_reference,head_name,head_mobile,address,city_village,sampark_area_id,society_id',
                    'homeVisit:id,group_family_assignment_id,karyakar_id,target_id,completed_at,completion_note,is_admin_override',
                ]),
            ])
            ->orderBy('group_code')
            ->get();

        $groupIds = $groups->pluck('id');
        $targets = Target::query()
            ->whereIn('group_id', $groupIds)
            ->where(function ($query) use ($karyakar): void {
                $query->whereNull('karyakar_id')->orWhere('karyakar_id', $karyakar->id);
            })
            ->whereIn('status', ['active', 'completed'])
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->with(['group:id,group_code', 'area:id,name', 'society:id,name'])
            ->orderByDesc('start_date')
            ->get();

        foreach ($targets as $target) {
            $progress->recalculate($target);
        }
        $targets = $targets->map->fresh(['group:id,group_code', 'area:id,name', 'society:id,name']);

        $openEvents = InactivityEvent::query()
            ->where('karyakar_id', $karyakar->id)
            ->whereIn('status', ['open', 'escalated'])
            ->with('group:id,group_code')
            ->latest('triggered_at')
            ->get();

        return Inertia::render('field/my-target', [
            'karyakar' => $karyakar->only(['id', 'center_id', 'full_name', 'karyakar_reference', 'mobile', 'category']),
            'groups' => $groups,
            'targets' => $targets,
            'badgeSummary' => $badges->summary($karyakar),
            'openEvents' => $openEvents,
            'adminChoices' => $adminChoices,
            'isAdminPreview' => $user->hasRole('super_admin') && $karyakar->user_id !== $user->id,
            'isSuperAdmin' => $user->hasRole('super_admin'),
        ]);
    }
}
