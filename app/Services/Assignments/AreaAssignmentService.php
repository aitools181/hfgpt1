<?php

namespace App\Services\Assignments;

use App\Models\Family;
use App\Models\Karyakar;
use App\Models\SamparkArea;
use App\Models\SankalpGroup;
use App\Models\Society;
use App\Models\Target;
use App\Services\AuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AreaAssignmentService
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function assign(SankalpGroup|Karyakar|Family $record, int $areaId, ?int $societyId, string $reason): Model
    {
        return DB::transaction(function () use ($record, $areaId, $societyId, $reason): Model {
            /** @var SankalpGroup|Karyakar|Family $record */
            $record = $record::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $area = SamparkArea::query()->findOrFail($areaId);
            if ($area->center_id !== $record->center_id || $area->status !== 'active') {
                throw ValidationException::withMessages(['sampark_area_id' => 'Area must be active and belong to the same Center.']);
            }

            $society = null;
            if ($societyId) {
                $society = Society::query()->findOrFail($societyId);
                if ($society->center_id !== $record->center_id || $society->sampark_area_id !== $area->id || $society->status !== 'active') {
                    throw ValidationException::withMessages(['society_id' => 'Society must be active and belong to the selected Area and Center.']);
                }
            }

            $old = ['sampark_area_id' => $record->sampark_area_id, 'society_id' => $record->society_id];
            $new = ['sampark_area_id' => $area->id, 'society_id' => $society?->id];
            $record->update($new);
            $this->audit->record('area_society_assignment', 'assignment_changed', $record::class, (string) $record->id, $old, $new, $reason, centerId: $record->center_id);

            // Keep current operational targets aligned with a Group's Area/Society.
            // Expired targets are historical snapshots and must not be rewritten.
            if ($record instanceof SankalpGroup) {
                Target::query()
                    ->where('group_id', $record->id)
                    ->whereIn('status', ['active', 'completed'])
                    ->whereDate('end_date', '>=', now()->toDateString())
                    ->lockForUpdate()
                    ->get()
                    ->each(function (Target $target) use ($new, $reason): void {
                        $targetOld = [
                            'sampark_area_id' => $target->sampark_area_id,
                            'society_id' => $target->society_id,
                        ];

                        if ($targetOld === $new) {
                            return;
                        }

                        $target->forceFill($new)->saveQuietly();
                        $this->audit->record(
                            'target',
                            'target_scope_synced_to_group',
                            Target::class,
                            (string) $target->id,
                            $targetOld,
                            $new,
                            $reason,
                            centerId: $target->center_id,
                        );
                    });
            }

            return $record;
        });
    }
}
