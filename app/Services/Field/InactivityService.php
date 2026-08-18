<?php

namespace App\Services\Field;

use App\Models\GroupFamilyAssignment;
use App\Models\HomeVisit;
use App\Models\InactivityEvent;
use App\Models\Karyakar;
use App\Models\SankalpGroup;
use App\Models\Target;
use App\Services\AuditTrail;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InactivityService
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function checkAll(?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?: now();
        $created = ['reminders' => 0, 'alerts' => 0];

        // Production-scale scan: bulk-load pending-work flags, current targets,
        // last activity and open-event presence for each Group chunk. The old
        // per-Karyakar query pattern could issue tens of thousands of queries at
        // the 10k-Group scale and create avoidable PostgreSQL pressure.
        SankalpGroup::query()
            ->where('status', 'active')
            ->with(['karyakarAssignments' => fn ($q) => $q->where('status', 'active')->with('karyakar')])
            ->chunkById(200, function (Collection $groups) use ($asOf, &$created): void {
                $groupIds = $groups->pluck('id')->map(fn ($id) => (int) $id)->all();
                if ($groupIds === []) {
                    return;
                }

                $pendingGroups = GroupFamilyAssignment::query()
                    ->whereIn('group_id', $groupIds)
                    ->where('status', 'active')
                    ->whereDoesntHave('homeVisit')
                    ->distinct()
                    ->pluck('group_id')
                    ->mapWithKeys(fn ($id) => [(int) $id => true]);

                $targetsByGroup = Target::query()
                    ->whereIn('group_id', $groupIds)
                    ->where('status', 'active')
                    ->whereDate('start_date', '<=', $asOf->toDateString())
                    ->whereDate('end_date', '>=', $asOf->toDateString())
                    ->orderByDesc('start_date')
                    ->get()
                    ->groupBy('group_id');

                $lastVisits = HomeVisit::query()
                    ->selectRaw('group_id, karyakar_id, MAX(completed_at) AS last_completed_at')
                    ->whereIn('group_id', $groupIds)
                    ->groupBy('group_id', 'karyakar_id')
                    ->get()
                    ->mapWithKeys(fn ($row) => [$row->group_id.':'.$row->karyakar_id => $row->last_completed_at]);

                $groupsWithOpenEvents = InactivityEvent::query()
                    ->whereIn('group_id', $groupIds)
                    ->whereIn('status', ['open', 'escalated'])
                    ->distinct()
                    ->pluck('group_id')
                    ->mapWithKeys(fn ($id) => [(int) $id => true]);

                foreach ($groups as $group) {
                    if (! $pendingGroups->has((int) $group->id)) {
                        // Avoid a no-op query for the large majority of completed
                        // Groups that have no open inactivity record to resolve.
                        if ($groupsWithOpenEvents->has((int) $group->id)) {
                            $this->resolveNoPendingWork($group, $asOf);
                        }
                        continue;
                    }

                    $groupTargets = $targetsByGroup->get($group->id, collect());
                    foreach ($group->karyakarAssignments as $assignment) {
                        $karyakar = $assignment->karyakar;
                        if (! $karyakar || $karyakar->status !== 'approved') {
                            continue;
                        }

                        $target = $this->selectPreloadedTarget($groupTargets, $karyakar);
                        $lastVisit = $lastVisits->get($group->id.':'.$karyakar->id);
                        $anchor = $this->activityAnchorFromValue($group, $target, $lastVisit, $asOf);
                        $result = $this->checkThresholds($group, $karyakar, $target, $anchor, $asOf);
                        $created['reminders'] += $result['reminder'] ? 1 : 0;
                        $created['alerts'] += $result['alert'] ? 1 : 0;
                    }
                }
            });

        return $created;
    }

    public function checkGroupKaryakar(SankalpGroup $group, Karyakar $karyakar, ?CarbonInterface $asOf = null): array
    {
        $asOf = $asOf ?: now();

        $hasPendingFamily = $group->familyAssignments()
            ->where('status', 'active')
            ->whereDoesntHave('homeVisit')
            ->exists();
        if (! $hasPendingFamily) {
            $this->resolveNoPendingWork($group, $asOf);
            return ['reminder' => false, 'alert' => false];
        }

        $target = $this->relevantTarget($group, $karyakar, $asOf);
        $anchor = $this->activityAnchor($group, $karyakar, $target, $asOf);

        return $this->checkThresholds($group, $karyakar, $target, $anchor, $asOf);
    }

    /** @param Collection<int, Target> $targets */
    private function selectPreloadedTarget(Collection $targets, Karyakar $karyakar): ?Target
    {
        $specific = $targets
            ->filter(fn (Target $target): bool => (int) $target->karyakar_id === (int) $karyakar->id)
            ->sortByDesc(fn (Target $target): string => $target->start_date->toDateString())
            ->first();

        if ($specific) {
            return $specific;
        }

        return $targets
            ->filter(fn (Target $target): bool => $target->karyakar_id === null)
            ->sortByDesc(fn (Target $target): string => $target->start_date->toDateString())
            ->first();
    }

    private function activityAnchorFromValue(SankalpGroup $group, ?Target $target, mixed $lastVisit, CarbonInterface $asOf): ?CarbonInterface
    {
        if ($lastVisit) {
            return Carbon::parse($lastVisit);
        }

        $activation = $group->activated_at?->copy();
        if ($target) {
            $start = $target->start_date->copy()->startOfDay();
            if ($start->lte($asOf)) {
                if ($activation && $activation->gt($start)) {
                    return $activation;
                }
                return $start;
            }
        }

        return $activation;
    }

    private function checkThresholds(SankalpGroup $group, Karyakar $karyakar, ?Target $target, ?CarbonInterface $anchor, CarbonInterface $asOf): array
    {
        if (! $anchor || $anchor->gt($asOf)) {
            return ['reminder' => false, 'alert' => false];
        }

        $days = (int) floor($anchor->diffInDays($asOf));
        $createdReminder = false;
        $createdAlert = false;

        if ($days >= 4) {
            $createdReminder = $this->openEvent($group, $karyakar, $target, 'reminder', $days, $anchor, $asOf);
        }
        if ($days >= 7) {
            $createdAlert = $this->openEvent($group, $karyakar, $target, 'alert', $days, $anchor, $asOf);
            $reminders = InactivityEvent::query()
                ->where('group_id', $group->id)
                ->where('karyakar_id', $karyakar->id)
                ->where('event_type', 'reminder')
                ->where('status', 'open')
                ->get();
            foreach ($reminders as $reminder) {
                $reminder->update(['status' => 'escalated']);
                $this->audit->record(
                    'inactivity',
                    'inactivity_reminder_escalated',
                    InactivityEvent::class,
                    (string) $reminder->id,
                    ['status' => 'open'],
                    ['status' => 'escalated', 'alert_threshold_days' => 7],
                    centerId: $reminder->center_id,
                );
            }
        }

        return ['reminder' => $createdReminder, 'alert' => $createdAlert];
    }

    public function resolveForActivity(int $groupId, int $karyakarId, HomeVisit $visit): int
    {
        return DB::transaction(function () use ($groupId, $karyakarId, $visit): int {
            $events = InactivityEvent::query()
                ->where('group_id', $groupId)
                ->where('karyakar_id', $karyakarId)
                ->whereIn('status', ['open', 'escalated'])
                ->lockForUpdate()
                ->get();

            foreach ($events as $event) {
                $oldStatus = $event->status;
                $event->update(['status' => 'resolved', 'resolved_at' => $visit->completed_at]);
                $this->audit->record(
                    'inactivity',
                    'inactivity_event_resolved',
                    InactivityEvent::class,
                    (string) $event->id,
                    ['status' => $oldStatus],
                    ['status' => 'resolved', 'home_visit_id' => $visit->id],
                    centerId: $event->center_id,
                );
            }

            $resolved = $events->count();
            $stillPending = $visit->group->familyAssignments()->where('status', 'active')->whereDoesntHave('homeVisit')->exists();
            if (! $stillPending) {
                $resolved += $this->resolveNoPendingWork($visit->group, $visit->completed_at);
            }

            return $resolved;
        }, 3);
    }

    private function resolveNoPendingWork(SankalpGroup $group, CarbonInterface $resolvedAt): int
    {
        $events = InactivityEvent::query()
            ->where('group_id', $group->id)
            ->whereIn('status', ['open', 'escalated'])
            ->get();

        foreach ($events as $event) {
            $oldStatus = $event->status;
            $event->update(['status' => 'resolved', 'resolved_at' => $resolvedAt]);
            $this->audit->record(
                'inactivity',
                'inactivity_event_resolved_no_pending_work',
                InactivityEvent::class,
                (string) $event->id,
                ['status' => $oldStatus],
                ['status' => 'resolved', 'reason' => 'No pending active Group Families'],
                centerId: $event->center_id,
            );
        }

        return $events->count();
    }

    private function relevantTarget(SankalpGroup $group, Karyakar $karyakar, CarbonInterface $asOf): ?Target
    {
        return Target::query()
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $asOf->toDateString())
            ->whereDate('end_date', '>=', $asOf->toDateString())
            ->where(function ($query) use ($karyakar): void {
                $query->where('karyakar_id', $karyakar->id)->orWhereNull('karyakar_id');
            })
            ->orderByRaw('CASE WHEN karyakar_id IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('start_date')
            ->first();
    }

    private function activityAnchor(SankalpGroup $group, Karyakar $karyakar, ?Target $target, CarbonInterface $asOf): ?CarbonInterface
    {
        $lastVisit = HomeVisit::query()
            ->where('group_id', $group->id)
            ->where('karyakar_id', $karyakar->id)
            ->latest('completed_at')
            ->value('completed_at');

        if ($lastVisit) {
            return Carbon::parse($lastVisit);
        }

        $activation = $group->activated_at?->copy();
        if ($target) {
            $start = $target->start_date->copy()->startOfDay();
            if ($start->lte($asOf)) {
                if ($activation && $activation->gt($start)) {
                    return $activation;
                }
                return $start;
            }
        }

        return $activation;
    }

    private function openEvent(SankalpGroup $group, Karyakar $karyakar, ?Target $target, string $type, int $days, CarbonInterface $anchor, CarbonInterface $asOf): bool
    {
        $existing = InactivityEvent::query()
            ->where('group_id', $group->id)
            ->where('karyakar_id', $karyakar->id)
            ->where('event_type', $type)
            ->whereIn('status', ['open', 'escalated'])
            ->first();

        if ($existing) {
            if ($days > $existing->inactivity_days) {
                $existing->update(['inactivity_days' => $days, 'updated_at' => now()]);
            }
            return false;
        }

        try {
            $event = InactivityEvent::query()->create([
                'center_id' => $group->center_id,
                'group_id' => $group->id,
                'karyakar_id' => $karyakar->id,
                'target_id' => $target?->id,
                'recipient_user_id' => $karyakar->user_id,
                'event_type' => $type,
                'inactivity_days' => $days,
                'status' => 'open',
                'activity_anchor_at' => $anchor,
                'triggered_at' => $asOf,
                'metadata' => [
                    'group_code' => $group->group_code,
                    'target_name' => $target?->name,
                    'threshold_days' => $type === 'alert' ? 7 : 4,
                ],
            ]);
        } catch (QueryException $exception) {
            // A second scheduler/process can race between the existence check
            // and INSERT. The partial unique index is authoritative; a duplicate
            // event is a harmless no-op rather than a reason to abort the scan.
            if ((string) $exception->getCode() === '23505') {
                return false;
            }
            throw $exception;
        }

        $this->audit->record(
            'inactivity',
            $type === 'alert' ? 'inactivity_alert_issued' : 'inactivity_reminder_issued',
            InactivityEvent::class,
            (string) $event->id,
            [],
            ['group_id' => $group->id, 'karyakar_id' => $karyakar->id, 'inactivity_days' => $days],
            centerId: $group->center_id,
        );

        return true;
    }
}
