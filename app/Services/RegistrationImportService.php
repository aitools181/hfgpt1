<?php

namespace App\Services;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\ImportBatch;
use App\Models\SamparkArea;
use App\Models\Society;
use Illuminate\Support\Facades\DB;
use Throwable;

class RegistrationImportService
{
    public function __construct(private readonly TabularFileReader $reader) {}

    public function importFamilies(ImportBatch $batch, string $absolutePath, string $extension): void
    {
        $rows = $this->reader->rows($absolutePath, $extension);
        $created = $updated = $skipped = $total = 0; $errors = [];
        foreach ($rows as $index => $row) {
            $total++;
            try {
                $familyId = trim((string) ($row['family_id'] ?? $row['familyid'] ?? ''));
                $headName = trim((string) ($row['head_name'] ?? $row['head_of_family'] ?? $row['family_name'] ?? ''));
                if ($familyId === '' || $headName === '') throw new \RuntimeException('family_id and head_name are required.');
                DB::transaction(function () use ($batch, $row, $familyId, $headName, &$created, &$updated): void {
                    $family = Family::query()->where('center_id', $batch->center_id)->where('external_family_id', $familyId)->first();
                    $wasNew = $family === null;
                    $family ??= new Family();
                    $family->fill([
                        'center_id' => $batch->center_id,
                        'external_family_id' => $familyId,
                        'source' => 'global',
                        'head_name' => $headName,
                        'head_mobile' => $row['head_mobile'] ?? $row['mobile'] ?? null,
                        'address' => $row['address'] ?? null,
                        'city_village' => $row['city_village'] ?? $row['city'] ?? $row['village'] ?? null,
                        'status' => 'active',
                    ]);
                    // Preserve the original registration provenance when a later SMVS Global import refreshes a Family.
                    if ($wasNew) {
                        $family->registered_at = now();
                        $family->registered_by = $batch->uploaded_by;
                    }
                    $family->save();
                    $memberId = trim((string) ($row['member_id'] ?? $row['memberid'] ?? ''));
                    $memberName = trim((string) ($row['member_name'] ?? $row['name'] ?? ''));
                    if ($memberId !== '' && $memberName !== '') {
                        $isHead = $this->boolValue($row['is_head'] ?? false);
                        if ($isHead) {
                            FamilyMember::query()->where('family_id', $family->id)->where(function ($query) use ($memberId): void {
                                $query->where('external_member_id', '!=', $memberId)->orWhereNull('external_member_id');
                            })->update(['is_head' => false]);
                        }
                        FamilyMember::query()->updateOrCreate(
                            ['family_id' => $family->id, 'external_member_id' => $memberId],
                            ['name' => $memberName, 'gender' => $this->gender($row['gender'] ?? null), 'age' => $this->age($row['age'] ?? null), 'mobile' => $row['member_mobile'] ?? null, 'relationship' => $row['relationship'] ?? null, 'is_head' => $isHead, 'status' => 'active']
                        );
                    }
                    $wasNew ? $created++ : $updated++;
                }, 3);
            } catch (Throwable $e) {
                if ($this->isInfrastructureFailure($e)) {
                    throw $e;
                }
                $skipped++;
                if (count($errors) < 100) $errors[] = ['row' => $index + 2, 'message' => mb_substr($e->getMessage(), 0, 1000)];
            }
        }
        $batch->update(['status' => $errors === [] ? 'completed' : 'completed_with_errors', 'total_rows' => $total, 'created_rows' => $created, 'updated_rows' => $updated, 'skipped_rows' => $skipped, 'errors' => $errors ?: null, 'completed_at' => now()]);
    }

    public function importAreas(ImportBatch $batch, string $absolutePath, string $extension): void
    {
        $rows = $this->reader->rows($absolutePath, $extension);
        $created = $updated = $skipped = $total = 0; $errors = [];
        foreach ($rows as $index => $row) {
            $total++;
            try {
                $areaName = trim((string) ($row['area_name'] ?? $row['sampark_area'] ?? $row['area'] ?? ''));
                if ($areaName === '') throw new \RuntimeException('area_name is required.');
                DB::transaction(function () use ($batch, $row, $areaName, &$created, &$updated): void {
                    $area = SamparkArea::query()->where('center_id', $batch->center_id)->where('name', $areaName)->first();
                    $wasNew = $area === null;
                    $area ??= new SamparkArea();
                    $area->fill(['center_id' => $batch->center_id, 'external_code' => $this->nullableString($row['area_code'] ?? null), 'name' => $areaName, 'city_village' => $row['city_village'] ?? null, 'status' => 'active'])->save();
                    $societyName = trim((string) ($row['society_name'] ?? $row['society'] ?? ''));
                    if ($societyName !== '') {
                        Society::query()->updateOrCreate(['center_id' => $batch->center_id, 'name' => $societyName], ['sampark_area_id' => $area->id, 'external_code' => $this->nullableString($row['society_code'] ?? null), 'status' => 'active']);
                    }
                    $wasNew ? $created++ : $updated++;
                }, 3);
            } catch (Throwable $e) {
                if ($this->isInfrastructureFailure($e)) {
                    throw $e;
                }
                $skipped++;
                if (count($errors) < 100) $errors[] = ['row' => $index + 2, 'message' => mb_substr($e->getMessage(), 0, 1000)];
            }
        }
        $batch->update(['status' => $errors === [] ? 'completed' : 'completed_with_errors', 'total_rows' => $total, 'created_rows' => $created, 'updated_rows' => $updated, 'skipped_rows' => $skipped, 'errors' => $errors ?: null, 'completed_at' => now()]);
    }

    private function isInfrastructureFailure(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $code = strtoupper((string) $current->getCode());
            if (str_starts_with($code, '08') || in_array($code, ['57P01', '57P02', '57P03', '53300', '53400'], true)) {
                return true;
            }

            $message = strtolower($current->getMessage());
            foreach ([
                'connection refused', 'connection reset', 'server closed the connection',
                'could not connect to server', 'no connection to the server',
                'terminating connection due to administrator command', 'too many clients',
                'remaining connection slots are reserved', 'network is unreachable',
            ] as $marker) {
                if (str_contains($message, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function gender(mixed $value): ?string { $v = strtolower(trim((string) $value)); return in_array($v, ['m', 'male'], true) ? 'male' : (in_array($v, ['f', 'female'], true) ? 'female' : null); }
    private function age(mixed $value): ?int { return is_numeric($value) && (int) $value >= 0 && (int) $value <= 120 ? (int) $value : null; }
    private function boolValue(mixed $value): bool { return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'y'], true); }
    private function nullableString(mixed $value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
}
