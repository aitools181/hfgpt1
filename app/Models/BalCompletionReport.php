<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalCompletionReport extends Model
{
    use Auditable;

    protected $fillable = [
        'center_id', 'bal_group_id', 'sanchalak_karyakar_id', 'society_id', 'family_id',
        'families_visited', 'families_completed', 'mobile', 'family_name', 'family_details',
        'completion_date', 'submitted_by',
    ];

    protected function casts(): array { return ['completion_date' => 'date']; }
    public function center(): BelongsTo { return $this->belongsTo(Center::class); }
    public function group(): BelongsTo { return $this->belongsTo(BalGroup::class, 'bal_group_id'); }
    public function sanchalak(): BelongsTo { return $this->belongsTo(Karyakar::class, 'sanchalak_karyakar_id'); }
    public function society(): BelongsTo { return $this->belongsTo(Society::class); }
    public function family(): BelongsTo { return $this->belongsTo(Family::class); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
}
