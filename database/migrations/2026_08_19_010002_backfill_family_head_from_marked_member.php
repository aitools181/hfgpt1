<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('families')->select(['id', 'head_name', 'head_mobile'])->orderBy('id')->chunkById(500, function ($families): void {
            $familyIds = $families->pluck('id')->all();
            if ($familyIds === []) {
                return;
            }

            // Prefer the most recently saved active member if historical bad data
            // contains more than one row marked as Head for the same Family.
            $heads = DB::table('family_members')
                ->whereIn('family_id', $familyIds)
                ->where('is_head', true)
                ->where('status', 'active')
                ->orderByDesc('id')
                ->get(['id', 'family_id', 'name', 'mobile'])
                ->groupBy('family_id')
                ->map(fn ($rows) => $rows->first());

            foreach ($families as $family) {
                $head = $heads->get($family->id);
                if (! $head || trim((string) $head->name) === '') {
                    continue;
                }

                $updates = ['head_name' => trim((string) $head->name)];
                $memberMobile = preg_replace('/[^0-9]/', '', trim((string) ($head->mobile ?? ''))) ?? '';
                if (strlen($memberMobile) === 12 && str_starts_with($memberMobile, '91')) {
                    $memberMobile = substr($memberMobile, 2);
                }
                if (preg_match('/^[6-9][0-9]{9}$/', $memberMobile) === 1) {
                    $updates['head_mobile'] = $memberMobile;
                }

                DB::table('families')->where('id', $family->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        // This is a corrective data backfill. Previous Family-head values are not
        // recoverable with certainty, so rollback intentionally leaves the repaired data.
    }
};
