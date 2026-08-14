<?php

namespace App\Services\Assignments;

use App\Models\Center;
use App\Models\Family;
use App\Models\GroupFamilyAssignment;
use App\Models\Karyakar;
use App\Models\RemainingFamilyReport;
use App\Models\SankalpGroup;
use App\Models\Target;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupAssignmentService
{
    public function __construct(
        private readonly GroupCodeGenerator $codes,
        private readonly GroupRules $rules,
        private readonly AuditTrail $audit,
    ) {}

    public function createGroup(int $centerId, array $karyakarIds, string $groupType, User $actor): SankalpGroup
    {
        return DB::transaction(function () use ($centerId, $karyakarIds, $groupType, $actor): SankalpGroup {
            $center = Center::query()->whereKey($centerId)->lockForUpdate()->firstOrFail();
            $karyakars = Karyakar::query()->whereIn('id', array_values(array_unique($karyakarIds)))->lockForUpdate()->get();
            $this->rules->validateKaryakars($karyakars, $groupType, $center->id);

            $group = SankalpGroup::query()->create([
                'center_id' => $center->id,
                'group_code' => $this->codes->next($center),
                'group_type' => $groupType,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);

            foreach ($karyakarIds as $index => $karyakarId) {
                $group->karyakarAssignments()->create([
                    'karyakar_id' => $karyakarId,
                    'position' => $index + 1,
                    'status' => 'active',
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);
            }

            $this->audit->record('groups', 'group_created', SankalpGroup::class, (string) $group->id, [], [
                'group_code' => $group->group_code,
                'group_type' => $groupType,
                'karyakar_ids' => array_values($karyakarIds),
            ], centerId: $center->id);

            return $group->fresh();
        });
    }

    public function assignFamily(SankalpGroup $group, int $familyId, string $type, User $actor, string $source = 'admin', ?string $note = null): GroupFamilyAssignment
    {
        return DB::transaction(function () use ($group, $familyId, $type, $actor, $source, $note): GroupFamilyAssignment {
            if (! in_array($type, ['fixed', 'remaining'], true)) {
                throw ValidationException::withMessages(['assignment_type' => 'Assignment type must be Fixed/Locked or Remaining.']);
            }

            $lockedGroup = SankalpGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            if ($lockedGroup->status === 'closed') {
                throw ValidationException::withMessages(['group' => 'Closed Groups cannot receive Family assignments.']);
            }
            if ($lockedGroup->status === 'active') {
                throw ValidationException::withMessages(['group' => 'Active Groups are locked. Transfer or reopen the Group before changing Family composition.']);
            }

            $family = Family::query()->whereKey($familyId)->lockForUpdate()->firstOrFail();
            if ($family->center_id !== $lockedGroup->center_id) {
                throw ValidationException::withMessages(['family_id' => 'The Sankalp Family must belong to the same Center as the Group.']);
            }
            if ($family->status !== 'active') {
                throw ValidationException::withMessages(['family_id' => 'Only active Sankalp Families can be assigned.']);
            }
            if ($source === 'karyakar') {
                if ($lockedGroup->society_id && $family->society_id !== $lockedGroup->society_id) {
                    throw ValidationException::withMessages(['family_id' => 'Karyakar may select only an unassigned Family in the Group Society.']);
                }
                if (! $lockedGroup->society_id && $lockedGroup->sampark_area_id && $family->sampark_area_id !== $lockedGroup->sampark_area_id) {
                    throw ValidationException::withMessages(['family_id' => 'Karyakar may select only an unassigned Family in the Group Sampark Area.']);
                }
                if (! $lockedGroup->society_id && ! $lockedGroup->sampark_area_id) {
                    throw ValidationException::withMessages(['family_id' => 'Assign the Group Area/Society before a Karyakar selects an existing Family, or use Report New Remaining Family.']);
                }
            }
            if (GroupFamilyAssignment::query()->where('family_id', $family->id)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['family_id' => 'This Sankalp Family already has an active Group assignment. Use the authorized Transfer workflow.']);
            }

            $assignments = GroupFamilyAssignment::query()->where('group_id', $lockedGroup->id)->where('status', 'active')->lockForUpdate()->get();
            if ($assignments->count() >= 10) {
                throw ValidationException::withMessages(['families' => 'A Group cannot contain more than exactly 10 active Sankalp Families.']);
            }
            $typeCount = $assignments->where('assignment_type', $type)->count();
            if ($type === 'fixed' && $typeCount >= 6) {
                throw ValidationException::withMessages(['assignment_type' => 'A Group can have at most 6 Fixed/Locked Families.']);
            }
            if ($type === 'remaining' && $typeCount >= 5) {
                throw ValidationException::withMessages(['assignment_type' => 'A Group can have at most 5 Remaining Families.']);
            }

            $assignment = GroupFamilyAssignment::query()->create([
                'group_id' => $lockedGroup->id,
                'family_id' => $family->id,
                'slot_number' => $this->nextAvailableSlot($assignments->pluck('slot_number')->all()),
                'assignment_type' => $type,
                'assignment_source' => $source,
                'status' => 'active',
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'change_note' => $note,
            ]);

            $this->audit->record('group_family_assignment', 'family_assigned', GroupFamilyAssignment::class, (string) $assignment->id, [], [
                'group_id' => $lockedGroup->id,
                'group_code' => $lockedGroup->group_code,
                'family_id' => $family->id,
                'family_reference' => $family->reference,
                'assignment_type' => $type,
                'assignment_source' => $source,
            ], $note, centerId: $lockedGroup->center_id);

            return $assignment;
        });
    }


    public function reportNewRemainingFamily(SankalpGroup $group, Karyakar $karyakar, array $data, User $actor): RemainingFamilyReport
    {
        return DB::transaction(function () use ($group, $karyakar, $data, $actor): RemainingFamilyReport {
            $group = SankalpGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            if ($group->status !== 'draft') {
                throw ValidationException::withMessages(['group' => 'Remaining Family reports are allowed only while the Group is in Draft status. Active and Closed Groups have locked Family composition.']);
            }
            if (! $group->karyakarAssignments()->where('karyakar_id', $karyakar->id)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['group' => 'Only a Karyakar assigned to this Group may report a new Remaining Family.']);
            }
            if ($karyakar->center_id !== $group->center_id) {
                throw ValidationException::withMessages(['group' => 'Karyakar and Group must belong to the same Center.']);
            }
            if ($karyakar->status !== 'approved') {
                throw ValidationException::withMessages(['karyakar' => 'Only an Approved Sankalp Karyakar may report a Remaining Family.']);
            }

            $family = Family::query()->create([
                'center_id' => $group->center_id,
                'sampark_area_id' => $group->sampark_area_id,
                'society_id' => $group->society_id,
                'source' => 'karyakar_reported',
                'head_name' => $data['head_name'],
                'head_mobile' => $data['head_mobile'] ?? null,
                'address' => $data['address'] ?? null,
                'city_village' => $data['city_village'] ?? null,
                'status' => 'pending_verification',
                'registered_at' => now(),
                'registered_by' => $actor->id,
            ]);
            $centerCode = Center::query()->whereKey($group->center_id)->value('code');
            $family->update(['manual_reference' => sprintf('HF-%s-R%06d', strtoupper((string) $centerCode), $family->id)]);

            $report = RemainingFamilyReport::query()->create([
                'group_id' => $group->id,
                'family_id' => $family->id,
                'karyakar_id' => $karyakar->id,
                'status' => 'pending',
                'note' => $data['note'] ?? null,
                'reported_at' => now(),
            ]);

            $this->audit->record('remaining_family_report', 'family_reported_by_karyakar', RemainingFamilyReport::class, (string) $report->id, [], [
                'group_id' => $group->id,
                'group_code' => $group->group_code,
                'family_id' => $family->id,
                'family_reference' => $family->manual_reference,
                'karyakar_id' => $karyakar->id,
                'status' => 'pending',
            ], $data['note'] ?? null, centerId: $group->center_id);

            return $report;
        });
    }

    public function reviewRemainingFamilyReport(RemainingFamilyReport $report, string $decision, User $actor, ?string $reviewNote = null): RemainingFamilyReport
    {
        return DB::transaction(function () use ($report, $decision, $actor, $reviewNote): RemainingFamilyReport {
            $report = RemainingFamilyReport::query()->whereKey($report->id)->lockForUpdate()->with(['family', 'group'])->firstOrFail();
            if ($report->status !== 'pending') {
                throw ValidationException::withMessages(['report' => 'This Remaining Family report has already been reviewed.']);
            }
            if (! in_array($decision, ['accepted', 'rejected'], true)) {
                throw ValidationException::withMessages(['decision' => 'Decision must be Accepted or Rejected.']);
            }

            if ($decision === 'accepted') {
                $report->family->update(['status' => 'active']);
                $this->assignFamily($report->group, $report->family_id, 'remaining', $actor, 'karyakar_report', $reviewNote ?? $report->note);
            } else {
                $report->family->update(['status' => 'inactive']);
            }

            $report->update([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
            ]);

            $this->audit->record('remaining_family_report', 'family_report_reviewed', RemainingFamilyReport::class, (string) $report->id, ['status' => 'pending'], ['status' => $decision], $reviewNote, centerId: $report->group->center_id);
            return $report;
        });
    }

    public function activate(SankalpGroup $group, User $actor): SankalpGroup
    {
        return DB::transaction(function () use ($group, $actor): SankalpGroup {
            $group = SankalpGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $assignments = GroupFamilyAssignment::query()->where('group_id', $group->id)->where('status', 'active')->lockForUpdate()->get();
            $activeKaryakars = $group->karyakarAssignments()->where('status', 'active')->with('karyakar')->get();
            if ($group->remainingFamilyReports()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['families' => 'Review all pending Remaining Family reports before activating the Group.']);
            }

            if ($activeKaryakars->count() !== 2 || $activeKaryakars->contains(fn ($a) => $a->karyakar?->status !== 'approved')) {
                throw ValidationException::withMessages(['karyakars' => 'Activation requires exactly 2 currently Approved Sankalp Karyakars.']);
            }

            $this->rules->validateFamilyComposition(
                $assignments->count(),
                $assignments->where('assignment_type', 'fixed')->count(),
                $assignments->where('assignment_type', 'remaining')->count(),
            );

            $old = ['status' => $group->status, 'activated_at' => $group->activated_at];
            $group->update(['status' => 'active', 'activated_at' => now(), 'closed_at' => null]);
            $this->audit->record('groups', 'group_activated', SankalpGroup::class, (string) $group->id, $old, ['status' => 'active', 'activated_at' => $group->activated_at?->toIso8601String()], centerId: $group->center_id);
            return $group;
        });
    }

    public function transferFamily(GroupFamilyAssignment $assignment, SankalpGroup $destination, string $newType, User $actor, string $reason): GroupFamilyAssignment
    {
        return DB::transaction(function () use ($assignment, $destination, $newType, $actor, $reason): GroupFamilyAssignment {
            $current = GroupFamilyAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'active') {
                throw ValidationException::withMessages(['family' => 'Only an active Family assignment can be transferred.']);
            }
            $sourceGroup = SankalpGroup::query()->whereKey($current->group_id)->lockForUpdate()->firstOrFail();
            $destination = SankalpGroup::query()->whereKey($destination->id)->lockForUpdate()->firstOrFail();
            if ($sourceGroup->id === $destination->id) {
                throw ValidationException::withMessages(['destination_group_id' => 'Select a different destination Group.']);
            }
            if ($sourceGroup->center_id !== $destination->center_id) {
                throw ValidationException::withMessages(['destination_group_id' => 'Family transfer must remain within the permitted Center.']);
            }
            if ($destination->status === 'closed') {
                throw ValidationException::withMessages(['destination_group_id' => 'Cannot transfer a Family to a closed Group.']);
            }
            if ($destination->status === 'active') {
                throw ValidationException::withMessages(['destination_group_id' => 'Destination Group is already active/locked. Reopen or choose a draft Group.']);
            }
            if (! in_array($newType, ['fixed', 'remaining'], true)) {
                throw ValidationException::withMessages(['assignment_type' => 'Transfer type must be Fixed/Locked or Remaining.']);
            }

            $destAssignments = GroupFamilyAssignment::query()->where('group_id', $destination->id)->where('status', 'active')->lockForUpdate()->get();
            if ($destAssignments->count() >= 10) {
                throw ValidationException::withMessages(['destination_group_id' => 'Destination Group already has 10 active Families.']);
            }
            $destTypeCount = $destAssignments->where('assignment_type', $newType)->count();
            if (($newType === 'fixed' && $destTypeCount >= 6) || ($newType === 'remaining' && $destTypeCount >= 5)) {
                throw ValidationException::withMessages(['assignment_type' => 'Destination Group has reached the limit for this Family assignment type.']);
            }

            $current->update([
                'status' => 'transferred',
                'ended_at' => now(),
                'transferred_to_group_id' => $destination->id,
                'change_note' => $reason,
            ]);
            if ($sourceGroup->status === 'active') {
                $openTargets = Target::query()
                    ->where('group_id', $sourceGroup->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->get();
                foreach ($openTargets as $target) {
                    $target->update(['status' => 'closed']);
                    $this->audit->record(
                        'targets',
                        'target_closed_after_family_transfer',
                        Target::class,
                        (string) $target->id,
                        ['status' => 'active'],
                        ['status' => 'closed', 'group_status' => 'draft'],
                        $reason,
                        centerId: $sourceGroup->center_id,
                    );
                }
                $sourceGroup->update(['status' => 'draft', 'activated_at' => null]);
            }

            $new = GroupFamilyAssignment::query()->create([
                'group_id' => $destination->id,
                'family_id' => $current->family_id,
                'slot_number' => $this->nextAvailableSlot($destAssignments->pluck('slot_number')->all()),
                'assignment_type' => $newType,
                'assignment_source' => 'transfer',
                'status' => 'active',
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'change_note' => $reason,
            ]);

            $this->audit->record('group_family_assignment', 'family_transferred', GroupFamilyAssignment::class, (string) $current->id, [
                'group_id' => $sourceGroup->id,
                'assignment_id' => $current->id,
                'assignment_type' => $current->getOriginal('assignment_type'),
                'status' => 'active',
            ], [
                'group_id' => $destination->id,
                'assignment_id' => $new->id,
                'assignment_type' => $newType,
                'status' => 'active',
            ], $reason, centerId: $sourceGroup->center_id);

            return $new;
        });
    }

    /** @param array<int,int|string> $used */
    private function nextAvailableSlot(array $used): int
    {
        $used = array_map('intval', $used);
        for ($slot = 1; $slot <= 10; $slot++) {
            if (! in_array($slot, $used, true)) {
                return $slot;
            }
        }
        throw ValidationException::withMessages(['families' => 'No Family slot is available in this Group.']);
    }
}
