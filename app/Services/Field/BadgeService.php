<?php

namespace App\Services\Field;

use App\Models\HomeVisit;
use App\Models\Karyakar;
use App\Models\KaryakarBadge;
use App\Services\AuditTrail;
use Illuminate\Support\Collection;

class BadgeService
{
    public const MILESTONES = [3, 6, 9, 12, 15];

    public function __construct(private readonly AuditTrail $audit) {}

    /** @return Collection<int,KaryakarBadge> */
    public function awardDue(Karyakar $karyakar, ?HomeVisit $trigger = null): Collection
    {
        $completed = HomeVisit::query()->where('karyakar_id', $karyakar->id)->count();
        $awarded = collect();

        foreach (self::MILESTONES as $milestone) {
            if ($completed < $milestone) {
                continue;
            }

            $badge = KaryakarBadge::query()->firstOrCreate(
                ['karyakar_id' => $karyakar->id, 'milestone' => $milestone],
                [
                    'badge_key' => "families_{$milestone}",
                    'awarded_at' => now(),
                    'trigger_home_visit_id' => $trigger?->id,
                ]
            );

            if ($badge->wasRecentlyCreated) {
                $this->audit->record(
                    'karyakar_badges',
                    'badge_awarded',
                    KaryakarBadge::class,
                    (string) $badge->id,
                    [],
                    ['karyakar_id' => $karyakar->id, 'milestone' => $milestone, 'completed_families' => $completed],
                    centerId: $karyakar->center_id,
                );
                $awarded->push($badge);
            }
        }

        return $awarded;
    }

    public function summary(Karyakar $karyakar): array
    {
        $completed = HomeVisit::query()->where('karyakar_id', $karyakar->id)->count();
        $current = collect(self::MILESTONES)->filter(fn (int $m) => $completed >= $m)->max();
        $next = collect(self::MILESTONES)->first(fn (int $m) => $completed < $m);

        return [
            'completedFamilies' => $completed,
            'currentMilestone' => $current ?: null,
            'nextMilestone' => $next ?: null,
            'remainingToNext' => $next ? max(0, $next - $completed) : 0,
            'earned' => KaryakarBadge::query()->where('karyakar_id', $karyakar->id)->orderBy('milestone')->get(['milestone', 'badge_key', 'awarded_at']),
        ];
    }
}
