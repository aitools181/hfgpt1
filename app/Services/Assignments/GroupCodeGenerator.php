<?php

namespace App\Services\Assignments;

use App\Models\Center;
use Illuminate\Support\Facades\DB;

class GroupCodeGenerator
{
    public function next(Center $center): string
    {
        DB::table('group_sequences')->insertOrIgnore([
            'center_id' => $center->id,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('group_sequences')->where('center_id', $center->id)->lockForUpdate()->first();
        $number = ((int) $sequence->last_number) + 1;
        DB::table('group_sequences')->where('center_id', $center->id)->update(['last_number' => $number, 'updated_at' => now()]);

        return sprintf('%s-%03d', strtoupper($center->code), $number);
    }
}
