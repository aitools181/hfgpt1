<?php

namespace App\Services\Field;

use App\Models\HomeVisit;
use App\Models\Karyakar;
use App\Models\SankalpGroup;
use App\Models\Target;

class CompletionReportService
{
    public function build(Karyakar $karyakar, SankalpGroup $group, HomeVisit $visit): array
    {
        $group->loadMissing('center.zone');
        $assignedCount = $group->familyAssignments()->where('status', 'active')->count();
        $groupCompleted = HomeVisit::query()->where('group_id', $group->id)->count();
        $ownCompleted = HomeVisit::query()->where('karyakar_id', $karyakar->id)->count();
        $ownGroupCompleted = HomeVisit::query()->where('group_id', $group->id)->where('karyakar_id', $karyakar->id)->count();
        $target = $visit->target_id ? Target::query()->find($visit->target_id) : Target::query()
            ->where('group_id', $group->id)
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->where(function ($query) use ($karyakar): void {
                $query->where('karyakar_id', $karyakar->id)->orWhereNull('karyakar_id');
            })
            ->orderByRaw('CASE WHEN karyakar_id IS NULL THEN 1 ELSE 0 END')
            ->latest('start_date')
            ->first();

        $targetQty = $target?->target_quantity ?? $assignedCount;
        $targetCompleted = $target?->completed_quantity ?? $groupCompleted;
        $ratio = $targetQty > 0 ? round(($targetCompleted / $targetQty) * 100, 2) : 0.0;

        return [
            'zone' => $group->center?->zone?->name,
            'center' => $group->center?->name,
            'group' => $group->group_code,
            'karyakar' => $karyakar->full_name,
            'completedFamilies' => $ownCompleted,
            'messagesDelivered' => $ownCompleted,
            'groupCompleted' => $groupCompleted,
            'groupPending' => max(0, $assignedCount - $groupCompleted),
            'ownGroupCompleted' => $ownGroupCompleted,
            'targetName' => $target?->name ?: 'Current Assignment',
            'targetQuantity' => $targetQty,
            'targetCompleted' => $targetCompleted,
            'targetPending' => max(0, $targetQty - $targetCompleted),
            'completionRatio' => $ratio,
            'analysis' => $ratio >= 100 ? 'Target Completed' : ($ratio >= 75 ? 'Near completion' : ($ratio >= 50 ? 'In progress' : 'Progress started')),
        ];
    }
}
