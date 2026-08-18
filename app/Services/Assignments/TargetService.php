<?php

namespace App\Services\Assignments;

use App\Models\Karyakar;
use App\Models\SamparkArea;
use App\Models\SankalpGroup;
use App\Models\Society;
use App\Models\Target;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TargetService
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function create(array $data, User $actor): Target
    {
        return DB::transaction(function () use ($data, $actor): Target {
            $group = SankalpGroup::query()->findOrFail($data['group_id']);
            if ($group->center_id !== (int) $data['center_id']) {
                throw ValidationException::withMessages(['group_id' => 'Group must belong to the selected Center.']);
            }
            if ($group->status !== 'active') {
                throw ValidationException::withMessages(['group_id' => 'Targets can be assigned only after the Group is active with its validated 2-Karyakar / 10-Family composition.']);
            }
            if (! $group->sampark_area_id) {
                throw ValidationException::withMessages(['sampark_area_id' => 'Assign the Group Sampark Area before creating a Target.']);
            }
            if ((int) $group->sampark_area_id !== (int) $data['sampark_area_id']) {
                throw ValidationException::withMessages(['sampark_area_id' => 'Target Area must match the Group Sampark Area.']);
            }
            $area = SamparkArea::query()->findOrFail($data['sampark_area_id']);
            if ($area->center_id !== $group->center_id) {
                throw ValidationException::withMessages(['sampark_area_id' => 'Area must belong to the Group Center.']);
            }

            $karyakar = null;
            if (! empty($data['karyakar_id'])) {
                $karyakar = Karyakar::query()->findOrFail($data['karyakar_id']);
                $isMember = $group->karyakarAssignments()->where('karyakar_id', $karyakar->id)->where('status', 'active')->exists();
                if ($karyakar->center_id !== $group->center_id || ! $isMember) {
                    throw ValidationException::withMessages(['karyakar_id' => 'Karyakar target must be assigned to an active member of the selected Group.']);
                }
            }

            if (! empty($data['society_id'])) {
                $society = Society::query()->findOrFail($data['society_id']);
                if ($society->center_id !== $group->center_id || $society->sampark_area_id !== $area->id) {
                    throw ValidationException::withMessages(['society_id' => 'Society must belong to the selected Area.']);
                }
                if ($group->society_id && (int) $group->society_id !== (int) $society->id) {
                    throw ValidationException::withMessages(['society_id' => 'Target Society must match the Group Society when the Group has a Society assignment.']);
                }
            } elseif ($group->society_id) {
                throw ValidationException::withMessages(['society_id' => 'Select the Group Society for this Target.']);
            }

            $target = Target::query()->create([
                'center_id' => $group->center_id,
                'group_id' => $group->id,
                'karyakar_id' => $karyakar?->id,
                'sampark_area_id' => $area->id,
                'society_id' => $data['society_id'] ?? null,
                'name' => $data['name'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'target_quantity' => $data['target_quantity'],
                'completed_quantity' => 0,
                'status' => 'active',
                'assigned_by' => $actor->id,
            ]);

            $this->audit->record('targets', 'target_assigned', Target::class, (string) $target->id, [], $target->only([
                'center_id', 'group_id', 'karyakar_id', 'sampark_area_id', 'society_id', 'start_date', 'end_date', 'target_quantity', 'status',
            ]), centerId: $target->center_id);
            return $target;
        }, 3);
    }
}
