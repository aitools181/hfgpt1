<?php

namespace App\Services\Field;

use App\Models\HomeVisit;
use App\Models\Target;
use Illuminate\Support\Collection;

class TargetProgressService
{
    /** @return Collection<int,Target> */
    public function recalculateForVisit(HomeVisit $visit): Collection
    {
        $targets = Target::query()
            ->where('group_id', $visit->group_id)
            ->whereIn('status', ['active', 'completed'])
            ->whereDate('start_date', '<=', $visit->completed_at->toDateString())
            ->whereDate('end_date', '>=', $visit->completed_at->toDateString())
            ->where(function ($query) use ($visit): void {
                $query->whereNull('karyakar_id')->orWhere('karyakar_id', $visit->karyakar_id);
            })
            ->get();

        foreach ($targets as $target) {
            $this->recalculate($target);
        }

        return $targets->map->fresh();
    }

    public function recalculate(Target $target): Target
    {
        $query = HomeVisit::query()
            ->where('group_id', $target->group_id)
            ->whereBetween('completed_at', [
                $target->start_date->copy()->startOfDay(),
                $target->end_date->copy()->endOfDay(),
            ]);

        if ($target->karyakar_id) {
            $query->where('karyakar_id', $target->karyakar_id);
        }
        if ($target->sampark_area_id) {
            $query->where('sampark_area_id', $target->sampark_area_id);
        }
        if ($target->society_id) {
            $query->where('society_id', $target->society_id);
        }

        $actualCompleted = $query->count();
        $completed = min($actualCompleted, (int) $target->target_quantity);
        $target->update([
            'completed_quantity' => $completed,
            'status' => $actualCompleted >= $target->target_quantity ? 'completed' : 'active',
        ]);

        return $target->fresh();
    }
}
