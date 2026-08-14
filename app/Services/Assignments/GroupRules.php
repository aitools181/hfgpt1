<?php

namespace App\Services\Assignments;

use App\Models\Karyakar;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class GroupRules
{
    public const TYPES = ['couple', 'two_male', 'two_female'];

    /** @param Collection<int,Karyakar> $karyakars */
    public function validateKaryakars(Collection $karyakars, string $groupType, int $centerId): void
    {
        if ($karyakars->count() !== 2 || $karyakars->pluck('id')->unique()->count() !== 2) {
            throw ValidationException::withMessages(['karyakar_ids' => 'A Group must contain exactly 2 different Sankalp Karyakars.']);
        }
        if ($karyakars->contains(fn (Karyakar $k) => $k->center_id !== $centerId)) {
            throw ValidationException::withMessages(['karyakar_ids' => 'Both Karyakars must belong to the Group Center.']);
        }
        if ($karyakars->contains(fn (Karyakar $k) => $k->status !== 'approved')) {
            throw ValidationException::withMessages(['karyakar_ids' => 'Only Approved Sankalp Karyakars can be assigned to a Group.']);
        }

        $genders = $karyakars->pluck('gender')->sort()->values()->all();
        $valid = match ($groupType) {
            'couple' => $genders === ['female', 'male'],
            'two_male' => $genders === ['male', 'male'],
            'two_female' => $genders === ['female', 'female'],
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['group_type' => 'Group Type must match the two Karyakars: Couple = one Male + one Female, 2 Male, or 2 Female.']);
        }
    }

    public function validateFamilyComposition(int $total, int $fixed, int $remaining): void
    {
        if ($total !== 10) {
            throw ValidationException::withMessages(['families' => 'An active Group must contain exactly 10 Sankalp Families.']);
        }
        if ($fixed < 5 || $fixed > 6) {
            throw ValidationException::withMessages(['families' => 'An active Group must contain 5 or 6 Fixed/Locked Families.']);
        }
        if ($remaining < 4 || $remaining > 5) {
            throw ValidationException::withMessages(['families' => 'An active Group must contain 4 or 5 Remaining Families.']);
        }
    }
}
