<?php

namespace App\Http\Controllers;

use App\Models\HomeVisit;
use App\Models\InactivityEvent;
use App\Models\Karyakar;
use App\Models\SankalpGroup;
use App\Models\User;
use App\Services\Monitoring\MonitoringAnalyticsService;
use App\Services\UserAdministrationScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MonitoringAnalyticsService $analytics, UserAdministrationScope $userScope): Response|RedirectResponse
    {
        $user = $request->user();
        if (($user->hasRole('nirdeshak') || $user->hasRole('nirikshak') || $user->hasRole('sanchalak'))
            && ! $user->hasRole('super_admin') && ! $user->hasRole('bn_karyalay_admin') && ! $user->hasRole('zonal_admin') && ! $user->hasRole('center_admin')) {
            return redirect()->route('bal.dashboard');
        }
        $monitoring = $analytics->dashboard($user, []);
        $summary = $monitoring['summary'];

        $linkedKaryakar = Karyakar::query()->where('user_id', $user->id)->where('status', 'approved')->first();
        $fieldSummary = null;
        if ($linkedKaryakar) {
            $activeGroupIds = SankalpGroup::query()
                ->where('status', 'active')
                ->whereHas('karyakarAssignments', fn ($q) => $q->where('status', 'active')->where('karyakar_id', $linkedKaryakar->id))
                ->pluck('id');
            $assignedFamilies = \App\Models\GroupFamilyAssignment::query()->whereIn('group_id', $activeGroupIds)->where('status', 'active')->count();
            $completedFamilies = HomeVisit::query()->where('karyakar_id', $linkedKaryakar->id)->whereIn('group_id', $activeGroupIds)->count();
            $allGroupCompleted = HomeVisit::query()->whereIn('group_id', $activeGroupIds)->count();
            $fieldSummary = [
                'activeGroups' => $activeGroupIds->count(),
                'assignedFamilies' => $assignedFamilies,
                'completedFamilies' => $completedFamilies,
                'pendingFamilies' => max(0, $assignedFamilies - $allGroupCompleted),
                'openReminders' => InactivityEvent::query()->where('karyakar_id', $linkedKaryakar->id)->whereIn('status', ['open', 'escalated'])->count(),
            ];
        }

        $managedUserCount = null;
        if ($user->hasPermission('manage_users')) {
            // Do not materialize every portal user on the dashboard. This count may
            // span thousands of users, so iterate in bounded Eloquent chunks.
            $managedUserCount = 0;
            foreach ($userScope->visibleUsers($user)->with('roles')->lazyById(250) as $candidate) {
                if ($userScope->canManageTarget($user, $candidate)) {
                    $managedUserCount++;
                }
            }
        }

        return Inertia::render('dashboard', [
            'summary' => [
                ...$summary,
                'users' => $managedUserCount,
            ],
            'fieldSummary' => $fieldSummary,
            'monitoring' => [
                'scopeLabel' => $this->scopeLabel($user),
                'femaleScopeLocked' => $monitoring['filters']['female_scope_locked'],
                'centerPerformance' => array_slice($monitoring['centerLeaderboard'], 0, 5),
                'zonePerformance' => array_slice($monitoring['zoneLeaderboard'], 0, 5),
                'genderDistribution' => $monitoring['genderDistribution'],
                'categoryDistribution' => array_slice($monitoring['categoryDistribution'], 0, 8),
            ],
            'quickActions' => $this->quickActions($user),
            'foundationStatus' => [
                ['name' => 'Authentication & organization scope', 'status' => 'ready'],
                ['name' => 'Phase 1 Registration & Data', 'status' => 'ready'],
                ['name' => 'Phase 2 Group & Assignment', 'status' => 'ready'],
                ['name' => 'Phase 3 Field Execution', 'status' => 'ready'],
                ['name' => 'Phase 4 Monitoring & Analysis', 'status' => 'ready'],
                ['name' => 'Phase 5 Bal Pravruti', 'status' => 'ready'],
                ['name' => 'Phase 6 Wireframe Support Modules', 'status' => 'ready'],
                ['name' => 'Phase 7 Production Hardening', 'status' => 'ready'],
            ],
        ]);
    }

    private function quickActions(User $user): array
    {
        $actions = [];
        $map = [
            ['register_family', 'Register Sankalp Family', '/registration/families'],
            ['register_karyakar', 'Register / Nominate Karyakar', '/registration/karyakars'],
            ['create_group', 'Create Group', '/assignments/groups'],
            ['assign_area_society', 'Assign Area / Society', '/assignments/areas'],
            ['assign_target', 'Assign Target', '/assignments/targets'],
            ['view_reports_analysis', 'View Progress & Analysis', '/monitoring/analysis'],
            ['access_bal_pravruti', 'Bal Pravruti Dashboard', '/bal-pravruti'],
            ['view_announcements', 'Announcements', '/support/announcements'],
            ['view_shared_content', 'Shared Content', '/support/content'],
            ['view_family_time', 'Family Time', '/support/family-time'],
        ];
        foreach ($map as [$permission, $label, $href]) {
            if ($user->hasPermission($permission)) {
                $actions[] = compact('label', 'href');
            }
        }
        return $actions;
    }

    private function scopeLabel(User $user): string
    {
        if ($user->hasRole('super_admin')) return 'Organization-wide';
        if ($user->hasRole('bn_karyalay_admin')) return 'BN Karyalay - female analysis scope';
        if ($user->hasRole('zonal_admin')) return 'Assigned Zone';
        if ($user->hasRole('center_admin') || $user->hasRole('computer_op')) return 'Assigned Center';
        if ($user->hasRole('karyakar')) return 'My assigned work';
        return 'Assigned role scope';
    }
}
