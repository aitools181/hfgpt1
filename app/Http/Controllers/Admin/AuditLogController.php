<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BalCompletionReport;
use App\Models\BalGroup;
use App\Models\BalGroupChild;
use App\Models\BalGroupSupervisor;
use App\Models\Center;
use App\Services\Bal\BalPravrutiService;
use App\Services\OrganizationalScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __invoke(Request $request, OrganizationalScope $scope, BalPravrutiService $balService): Response
    {
        $user = $request->user();
        $query = AuditLog::query()->with('center:id,name,code')->latest('created_at');

        if ($user->hasRole('karyakar')) {
            $query->where('user_id', $user->id);
        } elseif ($this->isBalRole($user)) {
            $this->applyBalAuditScope($query, $user, $balService);
        } elseif (! $user->hasRole('super_admin') && ! $user->hasRole('bn_karyalay_admin')) {
            $centerIds = $scope->centers($user)->pluck('id');
            $zoneIds = $scope->zones($user)->pluck('id');
            $query->where(function (Builder $q) use ($centerIds, $zoneIds, $user): void {
                if ($centerIds->isNotEmpty()) {
                    $q->whereIn('center_id', $centerIds);
                }
                if ($zoneIds->isNotEmpty()) {
                    $method = $centerIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $q->{$method}('zone_id', $zoneIds);
                }
                $q->orWhere('user_id', $user->id);
            });
        }

        $allowedCenterIds = $scope->centers($user)->pluck('id');
        $centerId = $request->integer('center_id') ?: null;
        if ($centerId && $allowedCenterIds->contains($centerId)) {
            $query->where('center_id', $centerId);
        }
        if ($module = trim((string) $request->query('module', ''))) {
            $query->where('module', $module);
        }
        if ($action = trim((string) $request->query('action', ''))) {
            $query->where('action', $action);
        }
        if ($role = trim((string) $request->query('user_role', ''))) {
            $query->where('user_role', $role);
        }
        if ($dateFrom = trim((string) $request->query('date_from', ''))) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo = trim((string) $request->query('date_to', ''))) {
            $query->whereDate('created_at', '<=', $dateTo);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('user_name', 'like', "%{$search}%")
                    ->orWhere('record_reference', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('record_type', 'like', "%{$search}%");
            });
        }

        $baseScope = AuditLog::query();
        if ($user->hasRole('karyakar')) {
            $baseScope->where('user_id', $user->id);
        } elseif ($this->isBalRole($user)) {
            $this->applyBalAuditScope($baseScope, $user, $balService);
        } elseif (! $user->hasRole('super_admin') && ! $user->hasRole('bn_karyalay_admin')) {
            $baseScope->where(function (Builder $q) use ($allowedCenterIds, $user): void {
                $q->whereIn('center_id', $allowedCenterIds)->orWhere('user_id', $user->id);
            });
        }

        return Inertia::render('admin/audit-logs', [
            'logs' => $query->limit(500)->get(),
            'filters' => $request->only(['center_id', 'module', 'action', 'user_role', 'date_from', 'date_to', 'search']),
            'options' => [
                'centers' => Center::query()->whereIn('id', $allowedCenterIds)->orderBy('name')->get(['id', 'name', 'code']),
                'modules' => (clone $baseScope)->whereNotNull('module')->distinct()->orderBy('module')->pluck('module')->values(),
                'actions' => (clone $baseScope)->whereNotNull('action')->distinct()->orderBy('action')->pluck('action')->values(),
                'roles' => (clone $baseScope)->whereNotNull('user_role')->distinct()->orderBy('user_role')->pluck('user_role')->values(),
            ],
        ]);
    }

    private function isBalRole(\App\Models\User $user): bool
    {
        return $user->hasRole('nirdeshak') || $user->hasRole('nirikshak') || $user->hasRole('sanchalak');
    }

    private function applyBalAuditScope(Builder $query, \App\Models\User $user, BalPravrutiService $balService): void
    {
        $groupIds = $balService->groupQuery($user)->pluck('id');
        $childIds = BalGroupChild::query()->whereIn('bal_group_id', $groupIds)->pluck('id');
        $supervisorIds = BalGroupSupervisor::query()->whereIn('bal_group_id', $groupIds)->pluck('id');
        $reportIds = BalCompletionReport::query()->whereIn('bal_group_id', $groupIds)->pluck('id');

        $query->where(function (Builder $outer) use ($user, $groupIds, $childIds, $supervisorIds, $reportIds): void {
            $outer->where('user_id', $user->id)
                ->orWhere(function (Builder $bal) use ($groupIds, $childIds, $supervisorIds, $reportIds): void {
                    $bal->where(function (Builder $q) use ($groupIds): void {
                        $q->where('record_type', BalGroup::class)->whereIn('record_id', $groupIds->map(fn ($id) => (string) $id));
                    })->orWhere(function (Builder $q) use ($childIds): void {
                        $q->where('record_type', BalGroupChild::class)->whereIn('record_id', $childIds->map(fn ($id) => (string) $id));
                    })->orWhere(function (Builder $q) use ($supervisorIds): void {
                        $q->where('record_type', BalGroupSupervisor::class)->whereIn('record_id', $supervisorIds->map(fn ($id) => (string) $id));
                    })->orWhere(function (Builder $q) use ($reportIds): void {
                        $q->where('record_type', BalCompletionReport::class)->whereIn('record_id', $reportIds->map(fn ($id) => (string) $id));
                    });
                });
        });
    }
}
