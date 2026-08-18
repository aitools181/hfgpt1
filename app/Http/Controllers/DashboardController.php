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
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MonitoringAnalyticsService $analytics, UserAdministrationScope $userScope): Response|RedirectResponse
    {
        $user = $request->user();
        if (($user->hasRole('nirdeshak') || $user->hasRole('nirikshak') || $user->hasRole('sanchalak'))
            && ! $user->hasRole('super_admin') && ! $user->hasRole('bn_karyalay_admin') && ! $user->hasRole('zonal_admin') && ! $user->hasRole('center_admin')) {
            return redirect()->route('bal.dashboard');
        }

        $dashboardWarnings = [];
        try {
            $monitoring = $analytics->dashboard($user, []);
        } catch (Throwable $e) {
            Log::error('Dashboard monitoring query failed; rendering degraded dashboard instead of HTTP 500.', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $monitoring = $this->fallbackMonitoring($user);
            $dashboardWarnings[] = 'Monitoring data is temporarily unavailable. Core navigation remains available; please check /health/ready and application logs.';
        }
        $summary = $monitoring['summary'];

        $fieldSummary = null;
        try {
            $linkedKaryakar = Karyakar::query()->where('user_id', $user->id)->where('status', 'approved')->first();
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
        } catch (Throwable $e) {
            Log::error('Dashboard field summary failed and was omitted.', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $dashboardWarnings[] = 'Field-work summary could not be loaded for this request.';
        }

        $managedUserCount = null;
        if ($user->hasPermission('manage_users')) {
            try {
                // Do not materialize every portal user on the dashboard. This count may
                // span thousands of users, so iterate in bounded Eloquent chunks.
                $managedUserCount = 0;
                foreach ($userScope->visibleUsers($user)->with('roles')->lazyById(250) as $candidate) {
                    if ($userScope->canManageTarget($user, $candidate)) {
                        $managedUserCount++;
                    }
                }
            } catch (Throwable $e) {
                $managedUserCount = null;
                Log::error('Dashboard managed-user count failed and was omitted.', [
                    'user_id' => $user->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                $dashboardWarnings[] = 'User-management count could not be loaded for this request.';
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
            'dashboardWarnings' => array_values(array_unique($dashboardWarnings)),
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

    private function fallbackMonitoring(User $user): array
    {
        return [
            'filters' => ['female_scope_locked' => $user->hasRole('bn_karyalay_admin')],
            'summary' => [
                'zones' => 0,
                'centers' => 0,
                'families' => 0,
                'members' => 0,
                'karyakars' => 0,
                'approvedKaryakars' => 0,
                'groups' => 0,
                'activeGroups' => 0,
                'activeTargets' => 0,
                'targetQuantity' => 0,
                'targetCompletedQuantity' => 0,
                'assignedFamilies' => 0,
                'completedFamilies' => 0,
                'balCompletedFamilies' => 0,
                'overallCompletedFamilies' => 0,
                'pendingFamilies' => 0,
                'completionPercentage' => 0.0,
                'homeVisits' => 0,
            ],
            'centerPerformance' => [],
            'zonePerformance' => [],
            'genderDistribution' => [
                ['label' => 'Male', 'key' => 'male', 'value' => 0],
                ['label' => 'Female', 'key' => 'female', 'value' => 0],
            ],
            'categoryDistribution' => [],
            'completionTrend' => [],
            'centerLeaderboard' => [],
            'zoneLeaderboard' => [],
        ];
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
