<?php

namespace App\Services\Field;

use App\Models\GroupFamilyAssignment;
use App\Models\HomeVisit;
use App\Models\Karyakar;
use App\Models\Target;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HomeVisitService
{
    public function __construct(
        private readonly TargetProgressService $progress,
        private readonly BadgeService $badges,
        private readonly InactivityService $inactivity,
        private readonly CompletionReportService $reports,
        private readonly AuditTrail $audit,
    ) {}

    public function complete(GroupFamilyAssignment $assignment, User $actor, array $data): array
    {
        return DB::transaction(function () use ($assignment, $actor, $data): array {
            $assignment = GroupFamilyAssignment::query()
                ->whereKey($assignment->id)
                ->lockForUpdate()
                ->with(['group.center.zone', 'family'])
                ->firstOrFail();

            if ($assignment->status !== 'active') {
                throw ValidationException::withMessages(['family' => 'Only an active Group Family assignment can be completed.']);
            }
            if ($assignment->group->status !== 'active') {
                throw ValidationException::withMessages(['group' => 'Home Visit completion is available only for an active Group.']);
            }
            if (HomeVisit::query()->where('group_family_assignment_id', $assignment->id)->exists()) {
                throw ValidationException::withMessages(['family' => 'This Sankalp Family is already marked completed for this Group assignment.']);
            }

            [$karyakar, $isOverride] = $this->resolveKaryakar($assignment, $actor, $data);
            $target = $this->resolveTarget($assignment, $karyakar, $data['target_id'] ?? null);
            $family = $assignment->family;

            $visit = HomeVisit::query()->create([
                'center_id' => $assignment->group->center_id,
                'group_id' => $assignment->group_id,
                'group_family_assignment_id' => $assignment->id,
                'family_id' => $assignment->family_id,
                'karyakar_id' => $karyakar->id,
                'target_id' => $target?->id,
                // Operational visit scope follows the selected Target/Group assignment first;
                // the Family master location is only a fallback when no operational scope exists.
                'sampark_area_id' => $target?->sampark_area_id ?: ($assignment->group->sampark_area_id ?: $family->sampark_area_id),
                'society_id' => $target?->society_id ?: ($assignment->group->society_id ?: $family->society_id),
                'message_delivered' => true,
                'completion_note' => $data['completion_note'] ?? null,
                'completed_at' => now(),
                'recorded_by' => $actor->id,
                'is_admin_override' => $isOverride,
                'override_reason' => $isOverride ? ($data['override_reason'] ?? null) : null,
            ]);

            $this->progress->recalculateForVisit($visit);
            $newBadges = $this->badges->awardDue($karyakar, $visit);
            $this->inactivity->resolveForActivity($assignment->group_id, $karyakar->id, $visit);

            $this->audit->record(
                'home_visits',
                $isOverride ? 'home_visit_completed_override' : 'home_visit_completed',
                HomeVisit::class,
                (string) $visit->id,
                [],
                [
                    'group_id' => $visit->group_id,
                    'family_id' => $visit->family_id,
                    'karyakar_id' => $visit->karyakar_id,
                    'target_id' => $visit->target_id,
                    'message_delivered' => true,
                ],
                $isOverride ? $visit->override_reason : null,
                centerId: $visit->center_id,
            );

            return [
                'visit' => $visit,
                'new_badges' => $newBadges->pluck('milestone')->values()->all(),
                'completion_report' => $this->reports->build($karyakar, $assignment->group, $visit),
            ];
        });
    }

    private function resolveKaryakar(GroupFamilyAssignment $assignment, User $actor, array $data): array
    {
        $linked = Karyakar::query()->where('user_id', $actor->id)->where('status', 'approved')->first();
        if ($linked && $assignment->group->karyakarAssignments()->where('karyakar_id', $linked->id)->where('status', 'active')->exists()) {
            return [$linked, false];
        }

        if (! $actor->hasRole('super_admin')) {
            throw ValidationException::withMessages(['authorization' => 'Only an assigned Sankalp Karyakar may record this Home Visit.']);
        }

        if (empty($data['karyakar_id']) || empty($data['override_reason'])) {
            throw ValidationException::withMessages([
                'karyakar_id' => 'Super Admin override requires an assigned Karyakar.',
                'override_reason' => 'Super Admin override requires a reason.',
            ]);
        }

        $karyakar = Karyakar::query()->whereKey($data['karyakar_id'])->where('status', 'approved')->firstOrFail();
        if (! $assignment->group->karyakarAssignments()->where('karyakar_id', $karyakar->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['karyakar_id' => 'Override Karyakar must be an active member of this Group.']);
        }

        return [$karyakar, true];
    }

    private function resolveTarget(GroupFamilyAssignment $assignment, Karyakar $karyakar, mixed $targetId): ?Target
    {
        if ($targetId) {
            $target = Target::query()->findOrFail((int) $targetId);
            $today = now()->toDateString();
            if ($target->group_id !== $assignment->group_id
                || ($target->karyakar_id && $target->karyakar_id !== $karyakar->id)
                || ! in_array($target->status, ['active', 'completed'], true)
                || $target->start_date->toDateString() > $today
                || $target->end_date->toDateString() < $today) {
                throw ValidationException::withMessages(['target_id' => 'Selected Target is not currently available for this Group/Karyakar.']);
            }
            return $target;
        }

        return Target::query()
            ->where('group_id', $assignment->group_id)
            ->whereIn('status', ['active', 'completed'])
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->where(function ($query) use ($karyakar): void {
                $query->where('karyakar_id', $karyakar->id)->orWhereNull('karyakar_id');
            })
            ->orderByRaw('CASE WHEN karyakar_id IS NULL THEN 1 ELSE 0 END')
            ->latest('start_date')
            ->first();
    }
}
